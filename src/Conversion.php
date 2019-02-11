<?php
namespace Webpit;

const TTL = 172800;
const MAX_SIZE = 20;
const MAX_SECS = 6;
const MAX_FILES = 10;


use React\EventLoop\LoopInterface;
use \React\Filesystem\Filesystem;

use Clue\React\SQLite\Database;
use Clue\React\SQLite\Result as DatabaseResult;
use Clue\React\Block;

use React\Promise\Deferred;
use React\Promise;
use React\Promise\FulfilledPromise as ResolvedPromise;
use React\Promise\RejectedPromise;
use function React\Promise\resolve;

use React\ChildProcess\Process;


class Conversion {


  private $database;
  private $reactLoop;
  private $fs;
  private $config;
  private $root;
  private $filesRoot;

  private $convertingImages = 0;
  private $convertingVideos = 0;


  /**
   *
   * Construct
   *
   */
  public function __construct(LoopInterface $loop, array $config=[]) {
    $this->reactLoop = $loop;

    $this->root = getcwd();
    $this->filesRoot = $this->root.'/files';

    $this->config['ttl'] = $config['ttl'] ?? Webpit\TTL;
    $this->config['max_size'] = $config['max_size'] ?? Webpit\MAX_SIZE;
    $this->config['max_secs'] = $config['max_secs'] ?? Webpit\MAX_SECS;
    $this->config['max_files'] = $config['max_files'] ?? Webpit\MAX_FILES;

    $this->fs = Filesystem::create( $this->reactLoop );
  }




  /**
   *
   * Destructor
   *
   */
  public function __destruct() {
    if($this->database) {
      $this->database->quit();
    }
  }




 /**
   *
   * Initializes the database
   *
   */
  public function initDatabase() {
    $defer = new Deferred;
    Database::open($this->reactLoop, $this->root.'/database.db')
      ->then(function(Database $db) use ($defer) {
        $this->database = $db;
        foreach($this->getCreateTables() as $sql) {
          $this->database->exec($sql);
        }
      })
      ->otherwise(function($e) use ($defer) {
        $defer->reject($e);
      });
    return $defer->promise();
  }




  /**
   *
   * Returns table creation sentences
   *
   */
  private function getCreateTables() {
    $sqls = [];
    $sqls[] = "
      CREATE TABLE IF NOT EXISTS conversions (
        id TEXT PRIMARY KEY,
        input_path TEXT,
        input_source TEXT,
        input_mime TEXT NULL,
        input_hash TEXT,
        user_agent TEXT,
        remote_address TEXT,
        created INTEGER,
        checked INTEGER NULL,
        status TEXT,
        output_path TEXT NULL,
        output_date INTEGER,
        output_token TEXT NULL
      )
    ";
    return $sqls;
  }




  /**
   *
   * Runs a process
   *
   */
  public function convert(array $row) {
    // TODO: Check if its running
    if($row['status'] != 'queued') {
      return new ResolvedPromise( $row );
    }
    $defer = new Deferred;
    if(empty($row['input_mime'])) {
      $mime = $this->getMime($row['input_path']);
    } else {
      $mime = new ResolvedPromise( $row['input_mime'] );
    }
    $mime
      ->then(function($mime) use ($defer, $row) {

      })
      ->otherwise(function($e) use ($defer) {
        $defer->reject($e);
      });
    $row['output_file'] = $row['id'].'.webp';
    $ffmpegBinary = dirname(__DIR__).'/bin/ffmpeg';
//    $process = new Process($ffmpegBinary.' -i "'.$row['input_file'].'" 
      })
      ->otherwise(function($e) use ($defer) {
        $defer->reject($e);
      });
    return $defer->promise();
  }




  /**
   *
   * Converts a video
   *
   */
  public function convertVideo(string $from, string $to, array $options=[]) {
    $defer = new Deferred;
    $this->convertingVideos++;
    $bin = dirname(__DIR__).'/bin/ffmpeg';
    // TODO: Options
    $proc = new Process( $bin.' -i "'.$from.'" "'.$to.'"' );
    $proc->start();
    $proc->on('exit', function($exitCode, $termSignal) use ($defer) {
      $this->convertingVideos--;
      if(empty($exitCode)) {
        return $defer->resolve($to);
      }
      $defer->reject( new \Exception("CONVERT VIDEO FAILED WITH EXIT $exitCode") );
    });
    return $defer->promise();
  }




  /**
   *
   * Converts a picture
   *
   */
  public function convertImage(string $from, string $to, array $options=[]) {
    $defer = new Deferred;
    $this->convertingImages++;
    $bin = dirname(__DIR__).'/bin/cwebp';
    // TODO: Options
    $proc = new Process( $bin.' "'.$from.'" -o "'.$to.'"' );
    $proc->start();
    $proc->on('exit', function($exitCode, $termSignal) use ($defer) {
      $this->convertingImages--;
      if(empty($exitCode)) {
        return $defer->resolve($to);
      }
      $defer->reject( new \Exception("CONVERT IMAGE FAILED WITH EXIT $exitCode") );
    });
    return $defer->promise();
  }




  /**
   *
   * Gets the mime type from the file system
   *
   */
  public function getMime(string $file) {
    $defer = new Deferred;
    $proc = new Process('file --mime-type -b "'.$file.'"');
    $proc->start();
    $buffer = '';
    $proc->stdout->on('data', function($chunk) use (&$buffer) {
      $buffer .= $chunk;
    });
    $proc->on('exit', function($exitCode, $termSignal) use ($defer, &$buffer) {
      if($exitCode) {
        return $defer->reject($exitCode);
      }
      $defer->resolve($buffer);
    });
    return $defer->promise();
  }




  /**
   *
   * Gets an entry
   *
   */
  public function get(string $uid) {
    $defer = new Deferred;
    $this->database->query("SELECT * FROM conversions WHERE id = ? ", [$uid])
      ->then(function(DatabaseResult $result) use ($defer) {
        $row = $result->rows[0] ?? false;
        $defer->resolve( $row );
      })
      ->otherwise(function($e) use ($defer) {
        $defer->reject($e);
      });
    return $defer->promise();
  }




  /**
   *
   * Deletes an entry
   *
   */
  public function delete(string $uid) {
    $defer = new Deferred;
    $this->database->query("DELETE FROM conversions WHERE id = ? ", [$uid])
      ->then(function(DatabaseResult $result) use ($defer) {
        $defer->resolve($result->changed);
      })
      ->otherwise(function($e) use ($defer) {
        $defer->reject($e);
      });
    return $defer->promise();
  }




  /**
   *
   * Saves an entry
   *
   */
  public function save(array &$row) {
    $defer = new Deferred;
    $keys = [];
    $values = [];
    $sets = [];
    foreach($row as $k=>$v) {
      $keys[] = '`'.$k.'`';
      $subk = ':'.$k;
      $values[$subk] = $v;
      if($k != 'id') {
        $sets[] = " `$k` = $subk ";
      }
    }
    $promise = ResolvedPromise(false);
    if(empty($row['id'])) {
      $row['id'] = $this->getUID();
      $keys[] = '`id`';
      $values[':id'] = $row['id'];
    } else {
      $promise = $this->database->query("UPDATE conversions SET ".implode(' , ', $sets)." WHERE id = :id ", $values);
    }
    $promise
      ->then(function($result) use ($defer, $values, $keys, &$row) {
        if($result instanceof DatabaseResult && $result->changed) {
          return $defer->resolve($row);
        }
        $this->database->query("INSERT INTO conversions ( ".implode(' , ', $keys)." ) VALUES ( ".implode(' , ', array_keys($values))." ) ")
          ->then(function($result) use ($defer, $values, &$rows) {
            $defer->resolve($rows);
          })
          ->otherwise(function($e) use ($defer) {
            $defer->reject($e);
          });
      })
      ->otherwise(function($e) use ($defer) {
        $defer->reject($e);
      });
    return $defer->promise();
  }




  /**
   *
   * Generates a unique id
   *
   */
  public function getUID() {
    return uniqid('', true);
  }





}

