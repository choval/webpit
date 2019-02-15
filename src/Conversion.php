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
use Psr\Http\Message\UploadedFileInterface;
use RingCentral\Psr7\Stream as Psr7Stream;

use React\ChildProcess\Process;
use React\Promise\Stream;




final class Conversion {


  static $fs;
  static $loop;
  static $converter;
  static $convertingImages = 0;
  static $convertingVideos = 0;

  static $saving = [];

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
  private $output_size;
  private $download_token;
  private $expires;

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
    'output_hash',
    'output_size',
    'download_token',
    'expires',
  ];




  /**
   *
   * Constructor
   *
   */
  public function __construct(array $row=[]) {
    if(empty(static::$fs)) {
      throw new \Exception('MISSING FILESYSTEM', 500);
    }
    if(empty(static::$loop)) {
      throw new \Exception('MISSING REACT LOOP', 500);
    }
    if(empty(static::$converter)) {
      throw new \Exception('MISSING CONVERTER', 500);
    }
    if(empty($row['id'])) {
      $row['id'] = static::generateId();
    }
    if(empty($row['created'])) {
      $row['created'] = time();
    }
    if(empty($row['status'])) {
      $row['status'] = 'pending';
    }
    if(empty($row['download_token'])) {
      $row['download_token'] = static::generateToken();
    }
    foreach($row as $k=>$v) {
      if(in_array($k, static::$columns)) {
        $this->{$k} = $v;
      }
    }
    static::$converter->addConversion($this);
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
        $defer->reject( new \Exception('EXIT CODE '.$exitCode, 500) );
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
   * Generates a token
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
   * Gets converting images
   *
   */
  public static function getConvertingImages() : int {
    return static::$convertingImages;
  }




  /** 
   *
   * Gets converting videos
   *
   */
  public static function getConvertingVideos() : int {
    return static::$convertingVideos;
  }




  /**
   *
   * Sets the input
   *
   */
  public function setInput($input) {
    $defer = new Deferred;
    $inputPath = $this->getInputPath();
    if($input instanceof UploadedFileInterface) {
      $input = $input->getStream();
    }
    $promise = false;
    if($input instanceof ReadableStreamInterface || $input instanceof Psr7Stream) {
      $this->input_source = 'STREAM';
      $promise = static::saveStream($input, $inputPath);
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
        $promise = static::saveStream($input, $inputPath);
      }
    }
    else {
      $defer->reject( new \Exception('UNKNOWN INPUT', 500) );
    }
    if($promise) {
      $promise
        ->then(function($size) use ($inputPath) {
          $this->input_size = $size;
          $this->input_path = $inputPath;
          return static::calculateFileHash( $inputPath );
        })
        ->then(function($hash) {
          $this->input_hash = $hash;
          return $this->calculateMime( $this->getInputPath() );
        })
        ->then(function($mime) {
          $this->input_mime = $mime;
          $this->status = 'queued';
          return $this->save();
        })
        ->then(function($saved) use ($defer) {
          $defer->resolve( $this );
        })
        ->otherwise(function($e) use ($defer) {
          $defer->reject($e);
        });
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
          $defer->reject( new \Exception('FWRITE FAILED', 500) );
          $stream->close();
        }
        $bytes += $written;
      });
      $stream->on('close', function() use ($defer, $fh, &$bytes) {
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
   * Retrieves a conversion if any
   *
   */
  static function get(string $id) {
    if(!empty(static::$saving[$id])) {
      return static::$saving[$id];
    }
    $defer = new Deferred;
    $path = static::dataPath($id);
    /*
    static::runCmd('test -f "'.$path.'" && cat "'.$path.'"')
      ->then(function($contents) use ($defer) {
        if(empty($
      })
      ->otherwise(function($e) use ($defer) {
        $defer->reject( new \Exception('NOT FOUND', 404) );
      });
      */
    $file = static::$fs->file($path);
    $file->exists()
      ->then(function() use ($path, $file) {
        return $file->getContents();
      })
      ->then(function($contents) use ($defer, $file) {
        // $file->close();
        if(empty($contents)) {
          $defer->reject(new \Exception('processing', 202));
          return;
        }
        $json = json_decode($contents, true);
        if(empty($json)) {
          $defer->reject(new \Exception('processing', 202));
          return;
        }
        $obj = new self($json);
        $defer->resolve( $obj );
      })
      ->otherwise(function($e) use ($defer) {
        $defer->reject( new \Exception('not found', 404) );
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
        static::$converter->delConversion($this);
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
    if( empty( static::$saving[ $this->id ] )) {
      $promise = new ResolvedPromise(false);
    } else {
      $promsise = static::$saving[$this->id];
    }
    $promise
      ->then(function() use ($defer) {
        static::$saving[ $this->id ] = $defer;
        $row = $this->getArrayCopy();
        $json = json_encode($row);
        $file = static::dataPath($this->id);
        return $this->saveStream($json, $file);
      })
      ->then(function($saved) use ($defer) {
        $defer->resolve($this);
        unset(static::$saving[$this->id]);
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
  public static function calculateFileHash(string $file) {
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
   * Calculates the file size
   *
   */
  public static function calculateFileSize(string $file) {
    $defer = new Deferred;
    static::runCmd('ls -l "'.$file.'" | awk \'{print $5}\'')
      ->then(function($size) use ($defer) {
        $size = trim($size);
        $defer->resolve( (int)$size );
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
  public static function calculateMime(string $file) {
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
   * Converts a picture
   *
   */
  public static function convertImage(string $from, string $to, array $options=[]) {
    $defer = new Deferred;
    static::$convertingImages++;
    $bin = dirname(__DIR__).'/bin/cwebp';
    $width = static::$converter->getMaxWidth();
    $quality = static::$converter->getQuality();
    $proc = new Process( $bin.' "'.$from.'" -mt -resize '.$width.' 0 -af -q '.$quality.' -o "'.$to.'"' );
    $proc->start(static::$loop);
    $proc->on('exit', function($exitCode, $termSignal) use ($defer, $to) {
      static::$convertingImages--;
      if(empty($exitCode)) {
        return $defer->resolve($to);
      }
      $defer->reject( new \Exception("CONVERT IMAGE FAILED WITH EXIT $exitCode", 500) );
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
    $width = static::$converter->getMaxWidth();
    // $height = static::$converter->getMaxHeight();
    $scale = '';
    // if($width || $height) {
    if($width) {
      $scale = 'scale=';
      $opts = [];
      if($width) {
        $opts[] = 'w='.$width;
      }
      $opts[] = 'h=0';
      /*
      if($height) {
        $opts[] = 'h='.$height;
      }
      */
      $opts[] = 'force_original_aspect_ratio=decrease';
      $scale .= implode(':',$opts);
    }
    $secs = static::$converter->getMaxSecs();
    $timeLimit = date('H:i:s', $secs);
    $quality = static::$converter->getQuality();
    $cmd = $bin.' -y -i "'.$from.'" -vcodec libwebp -q '.$quality.' -preset default -loop 0 -an -vf '.$scale.' -t '.$timeLimit.' "'.$to.'"';
    $proc = new Process( $cmd );
    $proc->start(static::$loop);
    $buffer = '';
    $proc->stdout->on('data', function($chunk) use (&$buffer) {
      $buffer .= $chunk;
    });
    $proc->on('exit', function($exitCode, $termSignal) use ($defer, $to, &$buffer, $cmd) {
      static::$convertingVideos--;
      if(empty($exitCode)) {
        return $defer->resolve($to);
      }
      $defer->reject( new \Exception("CONVERT VIDEO FAILED WITH EXIT $exitCode", 500) );
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
      return new RejectedPromise( new \Exception('INPUT PENDING', 500) );
    }
    if($this->status != 'queued') {
      return new RejectedPromise( new \Exception('ALREADY CONVERTED', 500) );
    }
    $defer = new Deferred;
    $promises = [];
    $from = $this->getInputPath();
    $to = $this->getOutputPath();
    $this->checked = time();
    $this->status = 'converting';
    $this->save()
      ->then(function($saved) {
        $mime = $this->getInputMime();
        $type = substr($mime, 0, 5);
        echo "CONVERTING $type {$this->id}\n";
        if($type == 'video') {
          $proc = static::convertVideo( $this->getInputPath(), $this->getOutputPath() );
        } else if($type == 'image') {
          $proc = static::convertImage( $this->getInputPath(), $this->getOutputPath() );
        } else {
          $proc = new RejectedPromise( new \Exception('UNKNOWN FORMAT', 500) );
        }
        return $proc;
      })
      ->then(function($outpath) {
        $this->status = 'completed';
        $this->output_date = time();
        $this->output_path = $outpath;
        $this->checked = time();
        $this->expires = time()+TTL;
        return static::calculateFileSize( $outpath );
      })
      ->then(function($size) {
        $this->output_size = $size;
        return static::calculateFileHash( $this->getOutputPath() );
      })
      ->then(function($hash) {
        $this->output_hash = $hash;
        return $this->save();
      })
      ->then(function($saved) use ($defer) {
        $defer->resolve($saved);
      })
      ->otherwise(function($e) use ($defer) {
        $this->error = $e->getMessage();
        $this->checked = time();
        $this->status = 'failed';
        $this->expires = time()+TTL;
        $this->save()
          ->always(function($saved) use ($defer, $e) {
            $defer->reject($e);
          });
        });
    return $defer->promise();
  }




  /**
   *
   * Gets the disk free space on the files folder
   *
   */
  public static function calculateDiskFreeSpace() {
    $defer = new Deferred;
    static::runCmd('df files | awk \'FNR==2{print $4}\'')
      ->then(function($bytes) use ($defer) {
        $bytes = trim($bytes);
        $bytes = (int)$bytes;
        $defer->resolve($bytes);
      })
      ->otherwise(function($e) use ($defer) {
        $defer->reject($e);
      });
    return $defer->promise();
  }




  /** 
   *
   * Checks if a conversion is expired
   *
   */
  public function isExpired() {
    return ($this->expires && $this->expires < time()) ? true : false;
  }




  // -------------------------- GETTERS ----------------------------



  public function getId() : string {
    return $this->id;
  }
  public function getStatus() : string {
    return $this->status;
  }
  public function getCreated() : int {
    return $this->created;
  }


  public function getInputSource() : string {
    return $this->input_source ?? '';
  }
  public function getInputHash() : string {
    return $this->input_hash ?? '';
  }
  public function getInputMime() : string {
    return $this->input_mime ?? '';
  }
  public function getInputPath() : string {
    if(empty($this->input_path)) {
      $this->input_path = 'files/'.$this->id;
    }
    return $this->input_path;
  }


  public function getOutputHash() : string {
    return $this->output_hash ?? '';
  }
  public function getOutputPath() : string {
    if(empty($this->output_path)) {
      $this->output_path = 'files/'.$this->id.'.webp';
    }
    return $this->output_path;
  }
  public function getOutputDate() : int {
    return $this->output_date;
  }
  public function getOutputSize() : int {
    return $this->output_size;
  }


  public function getDownloadToken() : string {
    return $this->download_token;
  }
  public function getExpires() : int {
    return $this->expires;
  }
  public function getChecked() : int {
    return $this->checked;
  }
  public function getError() : string {
    return $this->error;
  }


}

