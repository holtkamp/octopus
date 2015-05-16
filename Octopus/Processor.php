<?php
/**
 * @file: Processor core.
 */

namespace Octopus;

class Processor {
  /* @var TargetManager $targets */
  static protected $targets;

  // Redirects which we able too process.
  static protected $redirects = [301, 302, 303, 307, 308];

  // Currently running requests.
  // @todo: probably move to TargetManager
  protected $requests = [];

  static public $statCodes = ['failed' => 0];
  static public $totalData = 0;
  static public $brokenUrls = [];

  // to use with configuration elements
  static private $config;
  // Just to track execution time.
  static private $started;

  static protected $saveEnabled;
  static protected $savePath;

  // PHPReact core objects.
  private $dnsResolver;
  private $client;
  /* @var \React\EventLoop\LibEventLoop $loop (?) */
  private $loop;

  public function __construct(Config $config, TargetManager $targets) {
    self::$targets = $targets;
    self::$config = $config;
    if ((self::$saveEnabled = $config->outputMode == 'save') || ($config->outputBroken)) {
      self::$savePath = $config->outputDestination . '/';
      if (!mkdir(self::$savePath)) {
        throw new \Exception("Cannot create output directory: " . self::$savePath);
      }
    }
  }

  public function warmUp() {
    $this->loop = \React\EventLoop\Factory::create();

    $dnsResolverFactory = new \React\Dns\Resolver\Factory();
    $this->dnsResolver = $dnsResolverFactory->createCached(self::$config->dnsResolver, $this->loop);
    $factory = new \React\HttpClient\Factory();
    $this->client = $factory->create($this->loop, $this->dnsResolver);
  }

  public function run() {
    $processor = $this;
    $this->loop->addPeriodicTimer(self::$config->timerUI, '\Octopus\Processor::timerStat');
    $this->loop->addPeriodicTimer(self::$config->timerQueue, function ($timer) use ($processor) {
      if (self::$targets->getFreeSlots()) {
        $processor->spawnBundle();
      }
      elseif (0 === (self::$targets->countQueue() + self::$targets->countRunning())) {
        $timer->cancel();
      }
    });
    self::$started = microtime(true);
    $this->loop->run();
  }

  static public function timerStat($timer) {
    $countQueue = self::$targets->countQueue();
    $countRunning = self::$targets->countRunning();
    $countFinished = self::$targets->countFinished();

    $codeInfo = '';
    foreach (self::$statCodes as $code => $count) {
      $codeInfo = $codeInfo . $code . ':' . $count . ' ';
    }

    echo sprintf("%6.2f sec. Queued/running/done: %d/%d/%d. Stats: %s           \r",
      microtime(true) - self::$started, $countQueue, $countRunning, $countFinished, $codeInfo);
    if (0 === ($countQueue + $countRunning)) {
      $timer->cancel();
    }
  }

  public function spawn($id, $url) {
    $requestType = self::$config->requestType;
    /** @var \React\HttpClient\Request $request ; */
    $request = $this->client->request($requestType, $url);
    $request->octoUrl = $url;
    $request->octoId = $id;
    $request->on('response', function ($response, $req) {
      /** @var \React\HttpClient\Request $req ; */
      $response->octoUrl = $req->octoUrl;
      $response->octoId = $req->octoId;
      $response->on('data', "Octopus\\Processor::onData", $response);
      $response->on('end', "Octopus\\Processor::onEnd");
      $response->on('error', "Octopus\\Processor::onResponseError");
    });
    $request->on('error', "Octopus\\Processor::onRequestError");
    try {
      $request->end();
    }
    catch(\Exception $e) {
      echo "Problem of sending request: " . $e->getMessage() . PHP_EOL;
    }

    if (self::$config->spawnDelayMax) {
      usleep(rand(self::$config->spawnDelayMin, self::$config->spawnDelayMax));
    }
    $this->requests[$id] = $request;
  }

  public function spawnBundle() {
    for ($i = self::$targets->getFreeSlots(); $i > 0; $i--) {
      list($id, $url) = self::$targets->launchAny();
      $this->spawn($id, $url);
    }
  }

  static public function onData($data, $response) {
    /** @var \React\HttpClient\Response $response ; */

    if (self::$saveEnabled) {
      $path = self::$savePath . self::makeFilename($response->octoUrl, $response->octoId);
      if (file_put_contents($path, $data, FILE_APPEND) === false) {
        throw new \Exception("Cannot write file: $path");
      }
    }
    self::$totalData += strlen($data);
  }

  static public function onRequestError(\Exception $e) {
    /** @var \React\HttpClient\Request $request */
    $request = func_get_arg(1);
    self::$statCodes['failed']++;
    self::$targets->done($request->octoId);
    self::$brokenUrls[$request->octoUrl] = 'fail';
    echo $request->octoUrl . " request error: " . $e->getMessage() . PHP_EOL;
  }

  static public function onResponseError(\Exception $e) {
    /** @var \React\HttpClient\Response $response */
    $response = func_get_arg(1);
    self::$statCodes['failed']++;
    self::$targets->done($response->octoId);
    self::$brokenUrls[$response->octoUrl] = 'fail';
    echo $response->octoUrl . " response error: " . $e->getMessage() . PHP_EOL;
  }

  static public function onEnd($data, $response) {
    /** @var \React\HttpClient\Response $response ; */
    $doBonus = rand(0, 100) < self::$config->bonusRespawn;
    $code = $response->getCode();
    self::$statCodes[$code] = isset(self::$statCodes[$code]) ? self::$statCodes[$code] + 1 : 1;
    self::$targets->done($response->octoId);
    if (in_array($code, self::$redirects)) {
      $headers = $response->getHeaders();
      self::$targets->add($headers['Location']);
    }
    // Any 2xx code is 'success' for us.
    elseif ((int)($code/100) != 2) {
      self::$brokenUrls[$response->octoUrl] = $code;
    }
    elseif ($doBonus) {
      self::$targets->add($response->octoUrl);
    }
  }

  static public function makeFilename($octoUrl, $octoId) {
    return preg_replace("/[^a-zA-Z0-9]/", '_', $octoUrl . '_____' . $octoId);
  }
}
