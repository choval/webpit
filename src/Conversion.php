<?php
namespace Webpit;

const TTL = 172800;
const MAX_SIZE = 20;
const MAX_SECS = 6;
const MAX_FILES = 10;
const TOKEN_LENGTH = 8;


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

use React\Stream\WritableStreamInterface;
use React\Stream\ReadableStreamInterface;

use React\ChildProcess\Process;
use React\Promise\Stream;


class Conversion {


  private $database;
  private $reactLoop;
  private $fs;
  private $config;
  private $root;
  private $filesRoot;
  private $dataRoot;

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
    $this->dataRoot = $this->root.'/data';

    $this->config['ttl'] = $config['ttl'] ?? TTL;
    $this->config['max_size'] = $config['max_size'] ?? MAX_SIZE;
    $this->config['max_secs'] = $config['max_secs'] ?? MAX_SECS;
    $this->config['max_files'] = $config['max_files'] ?? MAX_FILES;

    $this->fs = Filesystem::create( $this->reactLoop );
  }




  /**
   *
   * Destructor
   *
   */
  public function __destruct() {
    /*
    if($this->database) {
      $this->database->quit();
    }
    */
  }




  /**
   *
   * Initializes the database
   *
   */
  public function initDatabase() {
    $defer = new Deferred;
    if($this->database) {
      $defer->resolve($this->database);
      return $defer->promise();
    }
    var_dump($this->filesRoot.'/database.db');
    Database::open($this->reactLoop, $this->filesRoot.'/database.db')
      ->then(function(Database $db) use ($defer) {
        $db->on('error', function($e) use ($defer) {
          var_dump($e->getMessage());
          $defer->reject($e);
        });
        $this->database = $db;
        foreach($this->getCreateTables() as $sql) {
          $this->database->exec($sql);
        }
        $defer->resolve( $db );
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
        error TEXT NULL,
        output_options TEXT NULL,
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
    $promises = [];
    $from = $row['input_path'];
    $to = $from.'.webp';
    $row['output_path'] = $to;
    $row['checked'] = time();
    $row['status'] = 'converting';
    $promises[] = $this->save($row);
    if(empty($row['input_mime'])) {
      $promises[] = $this->getMime($row['input_path']);
    } else {
      $promises[] = new ResolvedPromise( $row['input_mime'] );
    }
    Promise\all($promises)
      ->then(function($res) use ($defer, $row, $from, $to) {
        $saved = $res[0];
        $mime = $res[1];
        $type = substr($mime,0,5);
        $options = $row['output_options'] ?? [];
        if($type == 'image') {
          $promise = $this->convertImage($from, $to, $options);
        } else if($type == 'video') {
          $promise = $this->convertVideo($from, $to, $options);
        } else {
          $row['status'] = 'failed';
          $row['checked'] = time();
          $row['error'] = 'NOT SUPPORTER FORMAT: '.$mime;
          $this->save($row)
            ->then(function($saved) use ($defer, $row) {
              $defer->reject( new \Exception( $row['error'] ) );
            })
            ->otherwise(function($e) use ($defer) {
              $defer->reject( $e );
            });
        }
        if(isset($promise)) {
          $promise
            ->then(function($res) use ($defer, $row) {
              $row['status'] = 'completed';
              $row['output_date'] = time();
              $row['output_token'] = bin2hex(openssl_random_pseudo_bytes( TOKEN_LENGTH / 2 ));
              $this->save($row)
                ->then(function($saved) use ($defer, $row) {
                  $defer->resolve($row);
                })
                ->otherwise(function($e) use ($defer) {
                  $defer->reject($e);
                });
            })
            ->otherwise(function($e) use ($defer) {
              $defer->reject($e);
            });
        }
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
    $this->runCmd('file --mime-type -b "'.$file.'"')
      ->then(function($res) use ($defer) {
        $res = trim($res);
        $parts = explode(' ', $res, 2);
        $defer->resolve( $parts[0] );
      })
      ->otherwise(function($e) use ($defer) {
        $defer->reject($e);
      });
    return $defer->promise();
  }




  /**
   *
   * Gets the hash of the file
   *
   */
  public function getHash(string $file) {
    $defer = new Deferred;
    $this->runCmd('shasum "'.$file.'"')
      ->then(function($res) use ($defer) {
        $res = trim($res);
        $parts = explode(' ', $res, 2);
        $defer->resolve( $parts[0] );
      })
      ->otherwise(function($e) use ($defer) {
        $defer->reject($e);
      });
    return $defer->promise();
  }




  /**
   *
   * Runs a command
   *
   */
  private function runCmd(string $cmd) {
    $defer = new Deferred;
    $proc = new Process($cmd);
    $proc->start($this->reactLoop);
    $buffer = '';
    $proc->stdout->on('data', function($chunk) use (&$buffer) {
      $buffer .= $chunk;
    });
    $proc->on('exit', function($exitCode, $termSignal) use ($defer, &$buffer) {
      if($exitCode) {
        $defer->reject( new \Exception('EXIT CODE '.$exitCode) );
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
    $this->file($
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
    $promise = new ResolvedPromise(false);
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
          ->then(function($result) use ($defer, $values, &$row) {
            $defer->resolve($row);
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
    return sha1(openssl_random_pseudo_bytes( TOKEN_LENGTH ));
  }




  /**
   *
   * Creates a conversion entry from a stream
   *

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
        error TEXT NULL,
        output_options TEXT NULL,
        output_path TEXT NULL,
        output_date INTEGER,
        output_token TEXT NULL

   */
  public function create($stream, array $row=[]) {
    $defer = new Deferred;
    $uid = $this->getUID();
    $row['id'] = $uid;
    $row['input_path'] = $this->filePath($uid);
    $row['created'] = time();
    $row['status'] = 'queued';
    $this->saveStream($stream, $row['input_path'])
      ->then(function($saved) use ($defer, $row) {
        $this->getMime($row['input_path'])
          ->then(function($mime) use ($defer, $row) {
            $row['input_mime'] = $mime;
            $defer->resolve($row);
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
   * Returns the file path
   *
   */
  public function filePath(string $path) {
    if(substr($path,0,1) == '/') {
      return $path;
    }
    return $this->filesRoot.'/'.$path;
  }




  /**
   *
   * Saves a stream to a path
   *
   */
  public function saveStream(ReadableStreamInterface $stream, string $filePath) {
    $defer = new Deferred;
    $filePath = $this->filePath($filePath);
    $fh = fopen($filePath, 'w');
    $bytes = 0;
    $stream->on('error', function($e) use ($fh, $defer) {
      fclose($fh);
      $defer->reject( $e );
    });
    $stream->on('data', function($chunk) use ($fh, &$bytes, $defer, $stream) {
      $written = fwrite($fh, $chunk);
      if($written === false) {
        $defer->reject( new \Exception('FWRITE FAILED') );
        $stream->close();
      }
      $bytes += $written;
    });
    $stream->on('end', function() use ($defer, $fh, &$bytes) {
      fclose($fh);
      $defer->resolve($bytes);
    });
    return $defer->promise();
  }

  
}

