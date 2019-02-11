<?php
namespace Webpit;

use React\EventLoop\LoopInterface;
use React\Socket\Server as SocketServer;
use \React\Filesystem\Filesystem;

use React\Http\StreamingServer;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Http\Middleware\RequestBodyParserMiddleware;
use WyriHaximus\React\Http\Middleware\WithHeadersMiddleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

use React\Http\Response;
use React\Stream\ThroughStream;
use React\Promise\Deferred;
use React\Promise;
use React\Promise\FulfilledPromise;
use function React\Promise\resolve;

use Exception;

final class Server {

  private $reactLoop;
  private $config;
  private $socket;
  private $server;
  private $fs;
  private $root;
  private $database;
  private $conversion;


  /**
   *
   * Constructor
   *
   */
  public function __construct(LoopInterface $loop) {
    $this->reactLoop = $loop;
    $this->root = getcwd();

    $this->config = [];
    $this->config['port'] = ($port = (int)getenv('WEBPIT_PORT')) ? $port : 8080;
    $this->config['address'] = ($address = getenv('WEBPIT_ADDRESS')) ? $address : '0.0.0.0';
    $this->config['disable_index'] = empty(getenv('WEBPIT_DISABLE_INDEX')) ? false : true;
    $this->config['auth'] = getenv('WEBPIT_AUTH');
    $this->config['concurrency'] = ($concurrency = (int)getenv('WEBPIT_CONCURRENCY')) ? $concurrency : 30;
    $this->config['ttl'] = ($ttl = (int)getenv('WEBPIT_TTL')) ? $ttl : Webpit\TTL;
    $this->config['max_size'] = ($max_size = (int)getenv('WEBPIT_MAX_SIZE')) ? $max_size : Webpit\MAX_SIZE;
    $this->config['max_secs'] = ($max_secs = (int)getenv('WEBPIT_MAX_SECS')) ? $max_secs : Webpit\MAX_SECS;
    $this->config['max_files'] = ($max_files = (int)getenv('WEBPIT_MAX_FILES')) ? $max_files : Webpit\MAX_FILES;

    // Filesystem
    $this->fs = Filesystem::create( $this->reactLoop );
    $this->conversion = new Conversion($loop, $this->config);

    // Socket
    $this->socket = new SocketServer( $this->config['address'].':'.$this->config['port'] , $this->reactLoop );

    // Server
    $this->server = new StreamingServer([
          new WithHeadersMiddleware([
            'X-Powered-By' => 'WebPit/0.1',
          ]),
          function (ServerRequestInterface $request, callable $next) {
            return resolve($next($request))
              ->then(function(ResponseInterface $response) use ($request) {
                $method = $request->getMethod();
                $path = $request->getUri()->getPath();
                $status = $response->getStatusCode();
                $date = date('Y-m-d H:i:s');
                echo "[$date] $status - $method $path\n";
                return resolve($response);
              });
          },
          new LimitConcurrentRequestsMiddleware( $this->config['concurrency'] ),
          new RequestBodyBufferMiddleware( $this->config['max_size'] * 1024 * 1024 ),
          new RequestBodyParserMiddleware( $this->config['max_size'] * 1024 * 1024, 10),
          function (ServerRequestInterface $request) {
            return $this->handler($request);
          }
      ]);
    $this->server->on('error', function(Exception $e) {
      do {
        echo "SERVER ERROR: ".$e->getMessage()."\n";
        echo "---- DEBUG ----\n";
        print_r($e->getTraceAsString());
        echo "\n";
      } while( ($e = $e->getPrevious() ));
    });

  }




  /**
   *
   * Destructor
   *
   */
  public function __destruct() {
  }




  /** 
   *
   * Starts the server
   *
   */
  public function start() {
    $this->server->listen($this->socket);
    $this->conversion->initDatabase();  // TODO: This is a promise!
    // TODO: Echo
  }




  /**
   *
   * Handler
   *
   */
  public function handler(ServerRequestInterface $request) {
    $path = $request->getUri()->getPath();
    switch($path) {
      case '/':
        $path = '/index.html';
      case '/favicon.ico':
      case '/robots.txt':
        $file = basename($path);
        return $this->rawFile( dirname(__DIR__).'/static/'.$file );
      case '/convert':
        $method = $request->getMethod();
        if($method == 'POST') {
          return $this->handleConvert($request);
        }
      case '/download':
        return $this->handleDownload($request);
      case '/status':
        return $this->handleStatus($request);
    }

    $file = 'not_found.html';
    return $this->rawFile( dirname(__DIR__).'/static/'.$file, [], 404);
  }




  /**
   *
   * Returns a static file
   *
   */
  public function rawFile(string $file, array $headers=[], int $code=200) {
    $defer = new Deferred;
    if(  !isset($headers['Content-Type'])
      && !isset($headers['content-type'])
      && !isset($headers['Content-type']) ) {
      $headers['Content-Type'] = static::mimeByPath($file);
    }
    $this->fs->file($file)->exists()
      ->then(
        function() use ($file) {
          return $this->fs->file($file)->open('r');
        },
        function($e) use ($defer) {
          $defer->reject($e);
        }
      )
      ->then(
        function($fileStream) use ($defer, $headers, $code) {
          $stream = new ThroughStream;
          $response = new Response($code, $headers, $stream);
          $fileStream->pipe($stream);
          $defer->resolve( $response );
        },
        function($e) use ($defer) {
          $defer->reject($e);
        }
      );
    return $defer->promise();
  }




  /**
   *
   * Calculates the mime using the file name
   *
   */
  static public function mimeByPath(string $file) : string {
    if(strpos($file,'/') === false && strpos($file,'.') === false) {
      $ext = $file;
    } else {
      $ext = pathinfo( $file, PATHINFO_EXTENSION);
    }
    $ext = strtolower($ext);
    $mime = 'application/octet-stream';
    $mimes = [
      'css' => 'text/css',
      'gif' => 'image/gif',
      'html' => 'text/html',
      'htm' => 'text/htm',
      'ico' => 'image/x-icon',
      'jpeg' => 'image/jpeg',
      'jpg' => 'image/jpeg',
      'jpe' => 'image/jpeg',
      'js' => 'application/javascript',
      'json' => 'application/json',
      'pdf' => 'application/pdf',
      'png' => 'image/png',
      'svg' => 'image/svg+xml',
      'xls' => 'application/vnd.ms-excel',
      'webp' => 'image/webp',
    ];
    return $mimes[$ext] ?? $mime;
  }




  /**
   *
   * Handles conversion
   *
   */
  public function handleConvert(ServerRequestInterface $req) {
    $headers = $req->getHeaders();
    $contentType = $headers['Content-Type'] ?? $headers['Content-type'] ?? $headers['content-type'] ?? false;
    if($contentType) {
      $files = [];
      if($contentType == 'application/json') {
        $body = $req->getParsedBody();
        // TODO
      }
      else if(strpos($contentType, 'multipart/form-data') !== false) {
        $uploads = $request->getUploadedFiles();
        $uploadFiles = $uploads['files'] ?? [];
        if(isset($uploads['file'])) {
          $uploadFiles[] = $uploads['file'];
        }
        foreach($uploadFiles as $uploadFile) {
          if($uploadFile instanceof UploadedFileInterface) {
            if($uploadFile->getError() === \UPLOAD_ERR_OK) {
              $files[] = $uploadFile;
            }
          }
        }
      }
      // PROCESS EACH FILE
      foreach($files as $file) {
        if($file instanceof UploadedFileInterface) {
          // TODO
        } else {
          // TODO
        }
      }
    }
    exit;
  }




  /**
   *
   * Handles download
   *
   */
  public function handleDownload(ServerRequestInterface $req) {

  }




  /**
   *
   * Handles status
   *
   */
  public function handleStatus(ServerRequestInterface $req) {

  }




}

