<?php
namespace Webpit;

use React\EventLoop\LoopInterface;
use React\Filesystem\Filesystem;

use React\Promise\Deferred;
use React\Promise;
use React\Promise\FulfilledPromise as ResolvedPromise;
use React\Promise\RejectedPromise;

use React\Stream\WritableStreamInterface;
use React\Stream\ReadableStreamInterface;

use React\ChildProcess\Process;
use React\Promise\Stream;




final class Conversion {


  static $fs;
  static $loop;
  static $converter;
  static $convertingImages = 0;
  static $convertingVideos = 0;


  private $id;
  private $input_path;
  private $input_source;
  private $input_mime;
  private $input_hash;
  private $input_size;
  private $user_agent;
  private $remote_address;
  private $created;
  private $checked;
  private $status;
  private $error;
  private $logs;
  private $output_options;
  private $output_path;
  private $output_date;
  private $output_hash;
  private $output_token;

  static private $columns = [
    'id',
    'input_path',
    'input_source',
    'input_mime',
    'input_hash',
    'input_size',
    'user_agent',
    'remote_address',
    'created',
    'checked',
    'status',
    'error',
    'logs',
    'output_options',
    'output_path',
    'output_date',
    'output_token',
    'output_hash',
  ];




  /**
   *
   * Constructor
   *
   */
  public function __construct(array $row=[]) {
    if(empty(static::$fs)) {
      throw new \Exception('MISSING FILESYSTEM');
    }
    if(empty(static::$loop)) {
      throw new \Exception('MISSING REACT LOOP');
    }
    if(empty($row['id'])) {
      $row['id'] = static::generateId();
    }
    if(empty($row['created'])) {
      $row['created'] = gmdate('c');
    }
    if(empty($row['status'])) {
      $row['status'] = 'pending';
    }
    foreach($row as $k=>$v) {
      if(in_array($k, static::$columns)) {
        $this->{$k} = $v;
      }
    }
  }





  /**
   *
   * Runs a command
   *
   */
  private static function runCmd(string $cmd) {
    $defer = new Deferred;
    $proc = new Process($cmd);
    $proc->start(static::$loop);
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
   * Generate ID
   *
   */
  public static function generateId() {
    // This is pseudorand, but random enough 
    return sha1(microtime(true).rand());
  }




  /**
   *
   *
   *
   */
  public static function generateToken() {
    return sha1(openssl_random_pseudo_bytes( TOKEN_LENGTH ));
  }




  /**
   *
   * Sets the file system
   *
   */
  public static function setFilesystem(Filesystem $fs) {
    static::$fs = $fs;
  }




  /**
   *
   * Sets the loop 
   *
   */
  public static function setLoop(LoopInterface $loop) {
    static::$loop = $loop;
  }




  /**
   *
   * Sets the converter
   *
   */
  public static function setConverter(Converter $conv) {
    static::$converter = $conv;
  }




  /**
   *
   * Sets the input
   *
   */
  public function setInput($input) {
    $defer = new Deferred;
    $inputPath = $this->getInputPath();
    if($input instanceof ReadableStreamInterface) {
      $this->input_source = 'STREAM';
      static::saveStream($input, $inputPath)
        ->then(function($size) use ($defer, $inputPath) {
          $this->input_size = $size;
          $this->input_path = $inputPath;
          $this->status = 'queued';
          $this->save()
            ->then(function($saved) use ($defer, $size) {
              $defer->resolve( $size );
            })
            ->otherwise(function($e) use ($defer) {
              $defer->reject($e);
            });
        })
        ->otherwise(function($e) use ($defer) {
          $defer->reject($e);
        });
    }
    else if(is_string($input)) {
      // URI, download
      if(preg_match('/^(http|https):\/\//', $input)) {
        $this->input_source = $input;
        // TODO
      }
      // Full file
      else {
        $this->input_source = 'CONTENT';
        static::saveStream($input, $inputPath)
          ->then(function($size) use ($defer, $inputPath) {
            $this->input_size = $size;
            $this->input_path = $inputPath;
            $this->status = 'queued';
            $this->save()
              ->then(function($saved) use ($defer, $size) {
                $defer->resolve( $size );
              })
              ->otherwise(function($e) use ($defer) {
                $defer->reject($e);
              });
          })
          ->otherwise(function($e) use ($defer) {
            $defer->reject($e);
          });
      }
    }
    else {
      $defer->reject( new \Exception('UNKNOWN INPUT') );
    }
    return $defer->promise();
  }



  /**
   *
   * Data path
   *
   */
  static public function dataPath(string $id=null) {
    if(isset($this)) {
      $id = $this->id;
    }
    return 'data/'.$id.'.json';
  }





  /**
   *
   * Saves a file to the server, accepts a content or a stream
   *
   */
  public static function saveStream($stream, $filePath) {
    $defer = new Deferred;
    $bytes = 0;
    if($stream instanceof ReadableStreamInterface) {
      $fh = fopen($filePath, 'w');
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
    } else {
      $fh = fopen($filePath, 'w');
      $written = fwrite($fh, $stream);
      fclose($fh);
      $defer->resolve($written);
    }
    return $defer->promise();
  }



  /**
   *
   * Gets the input path
   *
   */
  public function getInputPath() : string {
    if(empty($this->input_path)) {
      $this->input_path = 'files/'.$this->id;
    }
    return $this->input_path;
  }



  /**
   *
   * Gets the output path
   *
   */
  public function getOutputPath() : string {
    if(empty($this->output_path)) {
      $this->output_path = 'files/'.$this->id.'.webp';
    }
    return $this->output_path;
  }




  /**
   *
   * Retrieves a conversion if any
   *
   */
  static function get(string $id) {
    $defer = new Deferred;
    $path = static::dataPath($id);
    static::$fs->file($path)->exists()
      ->then(function() use ($path) {
        return static::$fs->file($path)->getContents();
      })
      ->then(function($contents) use ($defer) {
        if(empty($contents)) {
          $defer->reject(new \Exception('NOT FOUND'));
          return;
        }
        $json = json_decode($contents, true);
        $obj = new self($json);
        $defer->resolve( $obj );
      })
      ->otherwise(function($e) use ($defer) {
        $defer->resolve(false);
//        $defer->reject($e);
      });
    return $defer->promise();
  }




  /**
   * 
   * Deletes the conersion
   *
   */
  public function delete() {
    $defer = new Deferred;
    $file = static::dataPath($this->id);
    $promises = [];
    $promises[] = static::runCmd('rm -f "'.$file.'"');
    $promises[] = static::runCmd('rm -f "'.$this->input_path.'"');
    $promises[] = static::runCmd('rm -f "'.$this->output_path.'"');
    Promise\all($promises)
      ->then(function($res) use ($defer) {
        $defer->resolve($res);
      })
      ->otherwise(function($e) use ($defer) {
        $defer->reject($e);
      });
    return $defer->promise();
  }




  /**
   *
   * Saves the conversion
   *
   */
  public function save() {
    $defer = new Deferred;
    $row = $this->getArrayCopy();
    $json = json_encode($row);
    $file = static::dataPath($this->id);
    $this->saveStream($json, $file)
      ->then(function($saved) use ($defer) {
        $defer->resolve($saved);
      })
      ->otherwise(function($e) use ($defer) {
        $defer->reject($e);
      });
    return $defer->promise();
  }




  /** 
   *
   * Gets an array copy
   *
   */
  public function getArrayCopy() {
    $tmp = [];
    foreach(static::$columns as $col) {
      if(isset( $this->{$col} ) ) {
        $tmp[ $col ] = $this->{$col};
      }
    }
    return $tmp;
  }



  /**
   *
   * Gets the hash of the file
   *
   */
  public static function getFileHash(string $file) {
    $defer = new Deferred;
    static::runCmd('shasum "'.$file.'"')
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
   * Gets the input hash
   *
   */
  public function getInputHash() {
    if(!empty($this->input_hash)) {
      return new ResolvedPromise( $this->input_hash );
    }
    $defer = new Deferred;
    static::getFileHash($this->input_path)
      ->then(function($hash) use ($defer) {
        $this->input_hash = $hash;
        $this->save()
          ->then(function($saved) use ($defer,$hash) {
            $defer->resolve($hash);
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
   * Gets the output hash
   *
   */
  public function getOutputHash() {
    if(!empty($this->ouput_hash)) {
      return new ResolvedPromise( $this->output_hash );
    }
    $defer = new Deferred;
    static::getFileHash($this->output_path)
      ->then(function($hash) use ($defer) {
        $this->output_hash = $hash;
        $this->save()
          ->then(function($saved) use ($defer,$hash) {
            $defer->resolve($hash);
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
   * Gets the mime type from the file system
   *
   */
  public static function getMime(string $file) {
    $defer = new Deferred;
    static::runCmd('file --mime-type -b "'.$file.'"')
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
   * Gets the file input mime
   *
   */
  public function getInputMime() {
    return static::getMime($this->input_path);
  }




  /**
   *
   * Converts a picture
   *
   */
  public static function convertImage(string $from, string $to, array $options=[]) {
    $defer = new Deferred;
    static::$convertingImages++;
    $bin = dirname(__DIR__).'/bin/cwebp';
    // TODO: Options
    $proc = new Process( $bin.' "'.$from.'" -o "'.$to.'"' );
    $proc->start(static::$loop);
    $proc->on('exit', function($exitCode, $termSignal) use ($defer, $to) {
      static::$convertingImages--;
      if(empty($exitCode)) {
        return $defer->resolve($to);
      }
      $defer->reject( new \Exception("CONVERT IMAGE FAILED WITH EXIT $exitCode") );
    });
    return $defer->promise();
  }




  /**
   *
   * Converts a video
   *
   */
  public static function convertVideo(string $from, string $to, array $options=[]) {
    $defer = new Deferred;
    static::$convertingVideos++;
    $bin = dirname(__DIR__).'/bin/ffmpeg';
    // TODO: Options
    $proc = new Process( $bin.' -y -i "'.$from.'" -vcodec libwebp -q 60 -preset default -loop 0 -an -vf scale=w=1080:h=1080:force_original_aspect_ratio=decrease -t 00:00:06 "'.$to.'"' );
    $proc->start(static::$loop);
    $proc->on('exit', function($exitCode, $termSignal) use ($defer, $to) {
      static::$convertingVideos--;
      if(empty($exitCode)) {
        return $defer->resolve($to);
      }
      $defer->reject( new \Exception("CONVERT VIDEO FAILED WITH EXIT $exitCode") );
    });
    return $defer->promise();
  }




  /**
   *
   * Runs a process
   *
   */
  public function convert() {
    if($this->status == 'pending') {
      return new RejectedPromise( new \Exception('INPUT PENDING') );
    }
    if($this->status != 'queued') {
      return new RejectedPromise( new \Exception('ALREADY CONVERTED') );
    }
    $defer = new Deferred;
    $promises = [];
    $from = $this->getInputPath();
    $to = $this->getOutputPath();
    $this->checked = time();
    $this->status = 'converting';
    $this->save()
      ->then(function($saved) {
        return $this->getInputMime();
      })
      ->then(function($mime) use ($defer) {
        $type = substr($mime, 0, 5);
        if($type == 'video') {
          $proc = static::convertVideo( $this->getInputPath(), $this->getOutputPath() );
        } else if($type == 'image') {
          $proc = static::convertImage( $this->getInputPath(), $this->getOutputPath() );
        } else {
          $proc = new RejectedPromise( new \Exception('UNKNOWN FORMAT') );
        }
        return $proc;
      })
      ->then(function ($outpath) use ($defer) {
        $this->status = 'completed';
        $this->output_date = gmdate('c');
        $this->save()
          ->then(function($saved) use ($defer) {
            $defer->resolve($saved);
          })
          ->otherwise(function($e) use ($defer) {
            $defer->reject($e);
          });
      })
      ->otherwise(function($e) use ($defer) {
        $this->error = $e->getMessage();
        $this->checked = time();
        $this->status = 'failed';
        $this->save()
          ->always(function($saved) use ($defer, $e) {
            $defer->reject($e);
          });
        });
    return $defer->promise();
  }




  /**
   *
   * Returns the status
   *
   */
  public function getStatus() {
    return $this->status;
  }

}

