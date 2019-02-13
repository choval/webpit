<?php
namespace Webpit;


use React\EventLoop\LoopInterface;
use React\Filesystem\Filesystem;

use Clue\React\Block;

use React\Promise\Deferred;
use React\Promise;
use React\Promise\FulfilledPromise as ResolvedPromise;
use React\Promise\RejectedPromise;



class Converter {


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

    Conversion::setFilesystem( $this->fs );
    Conversion::setLoop( $this->reactLoop );
    Conversion::setConverter( $this );

  }




  /**
   * 
   * Returns the time to live
   *
   */
  public function getTTL() : int {
    return $this->config['ttl'];
  }
  public function getTimeToLive() : int {
    return $this->getTTL();
  }




  /**
   * 
   * Returns the max seconds for videos
   *
   */
  public function getMaxSecs() : int {
    return $this->conifg['max_secs'];
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
   * Creates a conversion entry from a stream
   *
   */
  public function create($stream, array $row=[]) {
    $defer = new Deferred;
    $conv = new Conversion($row);
    $conv->setInput($stream)
      ->then(function($size) use ($defer, $conv) {
        $defer->resolve( $conv );
      })
      ->otherwise(function($e) use ($defer) {
        $defer->reject($e);
      });
    return $defer->promise();
  }




  
}

