<?php
class App {
  /** @property bool $debug */
  public static $debug;
  protected static $e_handlers = [];
  protected static $action_map;

  /**
   * Fetch annotated variables from $file using $map_file
   * @param string $file File that was annotated with import params (action or something else)
   * @param strign $map_file File with map of args or empty to use default
   * @return array
   */
  public static function getImportVarsArgs($file, $map_file = null) {
    $params = static::getJSON($map_file ?: config('common.param_map_file'));
    $args = [];
    if (isset($params[$file])) {
      foreach ($params[$file] as $param) {
        $args[] = $param['name'] . ':' . $param['type'] . (isset($param['default']) ? '=' . $param['default'] : '');
      }
    }
    return $args;
  }

  /**
   * Write json data into file
   * @param string $file File path to json
   * @param mixed $data Data to put in json file
   */
  public static function writeJSON($file, $data) {
    return file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Get json data from file
   * @param string $file
   * @return mixed
   */
  public static function getJSON($file) {
    if (!is_file($file)) {
      throw new Error('Cant find file ' . $file . '. Be sure you started init script to compile application');
    }

    return json_decode(file_get_contents($file), true);
  }

  /**
   * Log any message
   * @param string $message
   * @param array $dump
   * @param string $type error, info, wanr, notice
   * @return string идентификатор исключения
   */
  public static function log($message, array $dump = [], $type = 'error') {
    assert(is_string($message));
    assert(is_string($type));
    $id = hash('sha256', $message . ':' . implode('.', array_keys($dump)) . ':' . $type);
    $log_file = getenv('LOG_DIR') . '/' . gmdate('Ymd') . '-' . $type . '.log';
    $message =
      gmdate('[Y-m-d H:i:s T]')
      . "\t" . $id
      . "\t" . $message
      . "\t" . json_encode($dump, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\t"
      . json_encode(filter_input_array(INPUT_COOKIE), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
    ;
    error_log($message, 3, $log_file);
    return $id;
  }

  /**
   * Иницилизация работы приложения
   * @param array $config
   */
  public static function start(array $config = []) {
    foreach ($config as $param => $value) {
      static::$$param = $value;
    }

    if (!isset(static::$debug)) {
      static::$debug = getenv('PROJECT_ENV') === 'dev';
    }

    // Locale settings
    setlocale(LC_ALL, 'ru_RU.UTF8');

    // Error handler
    set_error_handler([static::class, 'handleError'], E_ALL);

    // Handle uncatched exceptions
    set_exception_handler([static::class, 'handleException']);

    // Register default Exception handler
    static::setExceptionHandler(Exception::class, static::createExceptionHandler());

    Autoload::register('Plugin', getenv('APP_DIR') . '/plugin');
    Autoload::register('App', getenv('APP_DIR') . '/src');
    Autoload::register('App\Model', getenv('APP_DIR') . '/src/model');
    Autoload::register('App\Component', getenv('APP_DIR') . '/src/component');
    Autoload::register('App\Lib', getenv('APP_DIR') . '/src/lib');
    Autoload::register('', getenv('APP_DIR') . '/vendor');
  }

  /**
   * Завершение исполнени приложени
   */
  public static function stop() {
    // Todo some work here
  }

  /**
   * @param Request $Request
   * @return View
   */
  public static function process(Request $Request, Response $Response) {
    if (!isset(static::$action_map)) {
      static::$action_map = static::getJSON(config('common.action_map_file'));
    }

    $process = function (&$_RESPONSE) use ($Request, $Response) {
      $_ACTION = static::$action_map[$Request->getAction()];
      extract(Input::get(static::getImportVarsArgs($_ACTION)));
      $_RESPONSE = include $_ACTION;

      return get_defined_vars();
    };

    $vars = $process($response);

    switch (true) {
      case $response === 1:
        $Response->header('Content-type', 'text/html;charset=utf-8');
        return View::create($Request->getAction())->set($vars);
        break;

      case $response instanceof View:
        $Response->header('Content-type', 'text/html;charset=utf-8');
        return $response->set($vars);
        break;

      case is_string($response):
        $Response->header('Content-type', 'text/plain;charset=utf-8');
        return View::fromString($response);
        break;

      case is_array($response):
      case is_object($response):
        $Response->header('Content-type', 'application/json;charset=utf-8');
        return View::fromString(json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        break;

      default:
        $Response->header('Content-type', 'text/plain;charset=utf-8');
        return View::fromString((string) $response);
    }
  }

  /**
   * Замена стандартного обработчика ошибок на эксепшены
   */
  public static function handleError($errno, $errstr, $errfile, $errline, $errcontext) {
    return static::error($errstr);
  }

  /**
   * Handle exception. Call handlers and do some staff
   * @param Throwable $Exception
   */
  public static function handleException(Throwable $Exception) {
    $Exception->id = static::log($Exception->getMessage(), ['trace' => $Exception->getTraceAsString()], 'error');

    $exception = get_class($Exception);
    do {
      if (isset(static::$e_handlers[$exception])) {
        $func = static::$e_handlers[$exception];
        return $func($Exception);
      }
    } while (false !== $exception = get_parent_class($exception));
  }

  public static function createExceptionHandler($code = 500, $type = 'html', Callable $format_func = null) {
    static $types = [
      'json' => 'application/json',
      'html' => 'text/html',
      'text' => 'text/plain',
    ];

    return function (Throwable $Exception) use ($code, $type, $format_func, $types) {
      switch (true) {
        case isset($format_func):
          $response = $format_func($Exception);
          break;
        case $type === 'json':
          $response = json_encode([
            'error' => $Exception->getMessage(),
            'trace' => App::$debug ? $Exception->getTrace() : [],
          ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
          break;

        case $type === 'html':
          $response = '<html><head><title>Error</title></head><body>'
             . '<p>Unhandled exception <b>'
             . get_class($Exception) . '</b> with message "' . $Exception->getMessage()
             . (static::$debug ? '" in file "' . $Exception->getFile() . ':' . $Exception->getLine() : '')
             . '"</p>';

          if (static::$debug) {
            $response .= '<p><ul>'
             . implode('<br/>', array_map(function ($item) { return '<li>' . $item . '</li>'; }, explode(PHP_EOL, $Exception->getTraceAsString())))
             . '</ul></p>'
             . '</body></html>'
            ;
          }
          break;

        default:
          $response = 'Error: ' . $Exception->getMessage();
          if (static::$debug) {
            $response .= PHP_EOL . $Exception->getTraceAsString();
          }
      }

      return Response::create($code)
        ->header('Content-type', $types[$type] . ';charset=utf8')
        ->send($response)
      ;
    };
  }

  /**
   * Assign handler for special exception that will be called when exception raises
   * @param string $exception
   * @param Callable $handler
   */
  public static function setExceptionHandler($exception, Callable $handler) {
    static::$e_handlers[$exception] = $handler;
  }

  /**
   * Хэндлер для управления ошибками ассертов
   * @param	stirng  $file
   * @param	string	$line
   * @param	string	$code
   * @throws Exception
   */
  public static function handleAssertion($file, $line, $code) {
    throw new Error('Assertion failed in file ' . $file . ' at line ' . $line . ' with code ' . $code);
  }

  /**
   * Generate error to stop next steps using special exception class name
   * @param string $error Message that describes error
   * @param string $class Exception class name to be raised
   * @throws \Exception
   */
  public static function error($error, $class = 'Exception') {
    throw new $class($error);
  }

  /**
   * Execute shell command in KISS core environment
   * @param string $cmd Command to be executed
   * @return string Result of execution
   */
  public static function exec($cmd) {
    $project_dir = getenv('PROJECT_DIR');
    return trim(`
      set -e
      cd $project_dir
      source ./env.sh
      $cmd
    `);
  }
}

class Autoload {
  protected static $inited = false;
  protected static $prefixes = [];

  /**
   * Init autoload mecahnism
   */
  protected static function init() {
    spl_autoload_register([static::class, 'load']);
    static::$inited = true;
  }

  /**
   * @param string $class Class to be loaded
   * @return bool
   */
  protected static function load($class) {
    assert(is_string($class));

    $prefix = $class;
    while (false !== $pos = strrpos($prefix, '\\')) {
      $prefix = substr($class, 0, $pos + 1);
      $relative = substr($class, $pos + 1);
      $mapped = static::loadMapped($prefix, $relative);
      if ($mapped) {
        return $mapped;
      }
      $prefix = rtrim($prefix, '\\');
    }

    return false;
  }

  /**
   * @param string $prefix
   * @param string $class
   */
  protected static function loadMapped($prefix, $class) {
    assert(is_string($prefix));
    assert(is_string($class));

    if (!isset(static::$prefixes[$prefix])) {
      return false;
    }

    foreach (static::$prefixes[$prefix] as $dir) {
      $file = $dir . str_replace('\\', '/', $class) . '.php';
      if (is_file($file)) {
        include $file;
        return $file;
      }
    }
    return false;
  }

  /**
   * Register new namespace and folder to be loaded from
   * @param string $prefix
   * @param string $dir
   * @param bool $prepend Priority for this
   */
  public static function register($prefix, $dir, $prepend = false) {
    assert(is_string($prefix));
    assert(is_string($dir) && is_dir($dir) /* Dir $dir does not exist */);
    assert(is_bool($prepend));

    if (!static::$inited) {
      static::init();
    }

    $prefix = trim($prefix, '\\') . '\\';
    $dir = rtrim($dir, '/') . '/';

    if (!isset(static::$prefixes[$prefix])) {
      static::$prefixes[$prefix] = [];
    }

    if ($prepend) {
      array_unshift(static::$prefixes[$prefix], $dir);
    } else {
      static::$prefixes[$prefix][] = $dir;
    }

  }
}

/**
 * Class Cookie
 * Work with cookies
 *
 * <code>
 * Cookie::add('first', 'value', time() + 100);
 * Cookie::add('onemore', 'value', time() + 100);
 * Cookie::send(); // Be sure to send cookie before headers sent
 * </code>
 *
 * <code>
 * $first = Cookie:get('first');
 * </code>
 */
class Cookie {
  protected static $cookies = [];

  /**
   * Get cookie by name
   * @param string $name
   * @param mixed $default
   */
  public static function get($name, $default = null) {
    return filter_has_var(INPUT_COOKIE, $name) ? filter_input(INPUT_COOKIE, $name) : $default;
  }

  /**
   * Set new cookie. Replace if exists
   * @param string $name
   * @param string $value
   * @param int $time Expire at time as timestamp
   * @param string $path Cookie save path
   * @return void
   */
  public static function set($name, $value, $time, $path = '/', $domain = null) {
    assert('is_string($name)');

    static::$cookies[$name] = [
      'name' => $name,
      'value' => $value,
      'time' => $time,
      'path' => $path,
      'domain' => $domain
    ];
  }

  /**
   * Add new cookie. Create new only if not exists
   * @param string $name
   * @param string $value
   * @param int $time Expire at time as timestamp
   * @param string $path Cookie save path
   * @return void
   */
  public static function add($name, $value, $time, $path = '/', $domain = null) {
    if (!filter_has_var(INPUT_COOKIE, $name)) {
      static::set($name, $value, $time, $path, $domain);
    }
  }

  /**
   * Send cookies headers
   */
  public static function send() {
    foreach (static::$cookies as $cookie) {
      setcookie($cookie['name'], $cookie['value'], $cookie['time'], $cookie['path'], $cookie['domain'] ?? null, config('common.proto') === 'https', 0 === strpos(getenv('SERVER_PROTOCOL'), 'HTTP'));
    }
  }
}

class Env {
  protected static $params = [
    'USER',
    'PROJECT',
    'PROJECT_DIR',
    'PROJECT_ENV',
    'PROJECT_REV',
    'APP_DIR',
    'STATIC_DIR',
    'CONFIG_DIR',
    'ENV_DIR',
    'BIN_DIR',
    'RUN_DIR',
    'LOG_DIR',
    'VAR_DIR',
    'TMP_DIR',
    'KISS_CORE',
    'HTTP_HOST',
  ];

  /**
   * Initialization of Application
   *
   * @return void
   */
  public static function init() {
    static::configure(getenv('APP_DIR') . '/config/app.ini.tpl');
    static::compileConfig();
    static::generateActionMap();
    static::generateURIMap();
    static::generateParamMap();
    static::generateTriggerMap();
    static::generateConfigs();
    static::prepareDirs();
  }

  /**
   * Configure all config tempaltes in dir $template or special $template file
   *
   * @param string $template
   * @param array $params
   * @return void
   */
  public static function configure($template, array $params = []) {
    // Add default params
    foreach (static::$params as $param) {
      $params['{{' . $param . '}}'] = getenv($param);
    }

    // Add extra params
    $params += [
      '{{DEBUG}}' => (int) App::$debug,
    ];

    foreach(is_dir($template) ? glob($template . '/*.tpl') : [$template] as $file) {
      file_put_contents(getenv('CONFIG_DIR') . '/' . basename($file, '.tpl'), strtr(file_get_contents($file), $params));
    }
  }

  /**
   * Compile config.json into fast php array to include it ready to use optimized config
   */
  protected static function compileConfig() {
    $env = getenv('PROJECT_ENV');

    // Prepare production config replacement
    foreach (parse_ini_file(getenv('CONFIG_DIR') . '/app.ini', true) as $group => $block) {
      if (false !== strpos($group, ':') && explode(':', $group)[1] === $env) {
        $origin = strtok($group, ':');
        $config[$origin] = array_merge($config[$origin], $block);
        $group = $origin;
      } else {
        $config[$group] = $block;
      }

      // Make dot.notation for group access
      foreach ($config[$group] as $key => &$val) {
        $config[$group . '.' . $key] = &$val;
      }
    }

    // Iterate to make dot.notation.direct.access
    $Iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($config));
    foreach ($Iterator as $leaf_value) {
      $keys = [];
      foreach (range(0, $Iterator->getDepth()) as $depth) {
        $keys[] = $Iterator->getSubIterator($depth)->key();
      }
      $config[join('.', $keys)] = $leaf_value;
    }

    file_put_contents(getenv('CONFIG_DIR') . '/config.php', '<?php return ' . var_export($config, true) . ';');
  }

  /**
   * Generate all configs for configurable plugins. It includes all plugin/_/configure.php files
   * @return void
   */
  protected static function generateConfigs() {
    $configure = function ($file) {
      return include $file;
    };

    foreach (glob(getenv('APP_DIR') . '/config/*/configure.php') as $file) {
      $configure($file);
    }
  }

  protected static function prepareDirs() {
    if (!is_dir(config('view.compile_dir'))) {
      mkdir(config('view.compile_dir'), 0755, true);
    }
  }

  /**
   * Generate nginx URI map for route request to special file
   */
  protected static function generateURIMap() {
    $map = [];
    foreach (static::getPHPFiles(getenv('APP_DIR') . '/actions') as $file) {
      $content = file_get_contents($file);
      if (preg_match_all('/^\s*\*\s*\@route\s+([^\:]+?)(\:(.+))?$/ium', $content, $m)) {
        foreach ($m[0] as $k => $matches) {
          $pattern = trim($m[1][$k]);
          $params  = isset($m[2][$k]) && $m[2][$k] ? array_map('trim', explode(',', substr($m[2][$k], 1))) : [];
          array_unshift($params, static::getActionByFile($file));
          $map[$pattern] = $params;
        }
      }
    }
    App::writeJSON(config('common.uri_map_file'), $map);
  }

  /**
   * Generate action => file_path map
   */
  protected static function generateActionMap() {
    $map = [];
    foreach (static::getPHPFiles(getenv('APP_DIR') . '/actions') as $file) {
      $map[static::getActionByFile($file)] = $file;
    }
    App::writeJSON(config('common.action_map_file'), $map);
  }

  /**
   * Generate parameters map from annotations in actions and triggers files
   */
  protected static function generateParamMap() {
    $map_files = [
      'actions'  => config('common.param_map_file'),
      'triggers' => config('common.trigger_param_file'),
    ];
    foreach ($map_files as $folder => $map_file) {
      $map = [];
      foreach (static::getPHPFiles(getenv('APP_DIR') . '/' . $folder) as $file) {
        $content = file_get_contents($file);
        if (preg_match_all('/^\s*\*\s*\@param\s+([a-z]+)\s+(.+?)$/ium', $content, $m)) {
          foreach ($m[0] as $k => $matches) {
            $map[$file][] = [
              'name'    => $param = substr(strtok($m[2][$k], ' '), 1),
              'type'    => $m[1][$k],
              'default' => trim(substr($m[2][$k], strlen($param) + 1)) ?: null,
            ];
          }
        }
      }
      App::writeJSON($map_file, $map);
    }
  }

  /**
   * Generate trigger map to be called on some event
   */
  protected static function generateTriggerMap() {
    $map = [];
    foreach (static::getPHPFiles(getenv('APP_DIR') . '/triggers') as $file) {
      $content = file_get_contents($file);
      if (preg_match_all('/^\s*\*\s*\@event\s+([^\$]+?)$/ium', $content, $m)) {
        foreach ($m[0] as $k => $matches) {
          $pattern = trim($m[1][$k]);
          if (!isset($map[$pattern])) {
            $map[$pattern] = [];
          }
          $map[$pattern] = array_merge($map[$pattern], [$file]);
        }
      }
    }
    App::writeJSON(config('common.trigger_map_file'), $map);
  }

   protected static function getActionByFile($file) {
     return substr(trim(str_replace(getenv('APP_DIR') . '/actions', '', $file), '/'), 0, -4);
   }

  /**
   * Helper for getting list of all php files in dir
   * @param string $dir
   * @return array
   */
  protected static function getPHPFiles($dir) {
    return ($res = trim(`find -L $dir -name '*.php'`)) ? explode(PHP_EOL, $res) : [];
  }
}

class Input {
  public static $is_parsed = false;
  public static $params = [];

  /**
   * Парсит и сохраняет все параметры в переменной self::$params
   *
   * @access protected
   * @return $this
   */
  protected static function parse() {
    if (filter_input(INPUT_SERVER, 'argc')) {
      $argv = filter_input(INPUT_SERVER, 'argv');
      array_shift($argv); // file
      static::$params['ACTION'] = array_shift($argv);
      static::$params += $argv;
    } elseif ((0 === strpos(filter_input(INPUT_SERVER, 'CONTENT_TYPE'), 'application/json'))) {
      static::$params = (array) filter_input_array(INPUT_GET) + (array) json_decode(file_get_contents('php://input'), true);
    } else {
      static::$params = (array) filter_input_array(INPUT_POST) + (array) filter_input_array(INPUT_GET);
    }

    static::$is_parsed = true;
  }

  public static function set(string $key, $value) {
    static::$is_parsed || static::parse();
    static::$params[$key] = $value;
  }

  /**
   * Получение переменной запроса
   *
   * <code>
   * $test = Input::get('test');
   *
   * $params = Input::get(['test:int=1']);
   * </code>
   */
  public static function get(...$args) {
    static::$is_parsed || static::parse();

    if (!isset($args[0])) {
      return static::$params;
    }

    // String key?
    if (is_string($args[0])) {
      return isset(static::$params[$args[0]])
        ? static::$params[$args[0]]
        : (isset($args[1]) ? $args[1] : null);
    }

    if (is_array($args[0])) {
      return static::extractTypified($args[0], function ($key, $default = null) {
        return static::get($key, $default);
      });
    }
    // Exctract typifie var by mnemonic rules as array


    trigger_error('Error while fetch key from input');
  }

  /**
   * Извлекает и типизирует параметры из массива args с помощью функции $fetcher, которая
   * принимает на вход ключ из массива args и значение по умолчанию, если его там нет
   *
   * @param array $args
   * @param Closure $fetcher ($key, $default)
   */
  public static function extractTypified(array $args, Closure $fetcher) {
    $params = [];
    foreach ($args as $arg) {
      preg_match('#^([a-zA-Z0-9_]+)(?:\:([a-z]+))?(?:\=(.+))?$#', $arg, $m);
      $params[$m[1]]  = $fetcher($m[1], isset($m[3]) ? $m[3] : '');

      // Нужно ли типизировать
      if (isset($m[2])) {
        typify($params[$m[1]], $m[2]);
      }
    }
    return $params;
  }
}

/**
 * Класс для работы с запросом и переменными запроса
 *
 * @final
 * @package Core
 * @subpackage Request
 */
class Request {
  /**
   * @property array $params все параметры, переданные в текущем запросе
   *
   * @property string $route имя действия, которое должно выполнится в выполняемом запросе
   * @property string $url адрес обрабатываемого запроса
   *
   * @property string $method вызываемый метод на данном запросе (GET | POST)
   * @property string $protocol протокол соединения, например HTTP, CLI и т.п.
   * @property string $referer реферер, если имеется
   * @property string $ip IP-адрес клиента
   * @property string $xff ip адрес при использовании прокси, заголовок: X-Forwarded-For
   * @property string $user_agent строка, содержащая USER AGENT браузера клиента
   * @property string $host Хост, который выполняет запрос
   * @property bool $is_ajax запрос посылается через ajax
   */

  private
  $params       = [],
  $action  = '',
  $route   = '',
  $url     = '';

  public static
  $time        = 0,
  $method      = 'GET',
  $protocol    = 'HTTP',
  $referer     = '',
  $ip          = '0.0.0.0',
  $real_ip     = '0.0.0.0',
  $xff         = '',
  $host        = '',
  $user_agent  = '',
  $languages   = [],
  $is_ajax     = false;

  /**
   * @param string|bool $url адрес текущего запроса
   */
  final protected function __construct($url) {
    assert(in_array(gettype($url), ['string', 'boolean']));

    $this->url  = $url;
  }

  /**
   * Получение ссылки на экземпляр объекта исходного запроса
   *
   * @static
   * @access public
   * @param $url
   * @return Request ссылка на объекта запроса
   */
  public static function create($url = true) {
    assert(in_array(gettype($url), ['string', 'boolean']));

    self::$time = time();
    if (filter_input(INPUT_SERVER, 'argc')) {
      self::$protocol = 'CLI';
    } else {
      self::$protocol = filter_input(INPUT_SERVER, 'HTTPS') ? 'HTTPS' : 'HTTP';
      self::$is_ajax = !!filter_input(INPUT_SERVER, 'HTTP_X_REQUESTED_WITH');
      self::$referer = filter_input(INPUT_SERVER, 'HTTP_REFERER');
      self::$xff = filter_input(INPUT_SERVER, 'HTTP_X_FORWARDED_FOR');

      // Эти переменные всегда определены в HTTP-запросе
      self::$method = filter_input(INPUT_SERVER, 'REQUEST_METHOD');
      self::$user_agent = filter_input(INPUT_SERVER, 'HTTP_USER_AGENT') ?: 'undefined';
      self::$ip = filter_input(INPUT_SERVER, 'REMOTE_ADDR');

      static::parseRealIp();
      preg_match_all('/([a-z]{1,8}(-[a-z]{1,8})?)\s*(;\s*q\s*=\s*(1|0\.[0-9]+))?/i', filter_input(INPUT_SERVER, 'HTTP_ACCEPT_LANGUAGE'), $lang);
      if ($lang && sizeof($lang[1]) > 0) {
        $langs = array_combine($lang[1], $lang[4]);

        foreach ($langs as $k => $v) {
          if ($v === '') {
            $langs[$k] = 1;
          }
        }
        arsort($langs, SORT_NUMERIC);
        static::$languages = $langs;
      }

      if ($url === true && $url = filter_input(INPUT_SERVER, 'REQUEST_URI')) {
        $url = rtrim($url, ';&?') ?: '/';
      }
    }

    return (new static($url))
      ->setRoute(Input::get('ROUTE'))
      ->setAction(Input::get('ACTION'))
    ;
  }

  /**
   * Parse IPS to prepare request
   * @return void
   */
  protected static function parseRealIp() {
    self::$real_ip = self::$ip;
    if (self::$xff && self::$xff !== self::$ip) {
      self::$real_ip = trim(strtok(self::$xff, ','));
    }
  }

  /**
   * Get current handled url for this request
   * @return string
   */
  public function getUrl() {
    return $this->url;
  }

  /**
   * Get part of url as path. /some/path for url /some/path?fuck=yea
   * @param string
   */
  public function getUrlPath() {
    return parse_url($this->url, PHP_URL_PATH);
  }

  /**
   * Get url query
   * @return string
   */
  public function getUrlQuery() {
    return parse_url($this->url, PHP_URL_QUERY);
  }


  /**
   * Get requested header
   * @param string $header
   * @return string
   */
  public function getHeader($header) {
    return filter_input(INPUT_SERVER, 'HTTP_' . strtoupper(str_replace('-', '_', $header)));
  }

  /**
   * Установка текущего роута с последующим парсингом его в действие и модуль
   *
   * @access public
   * @param string $route
   * @return $this
   */
  public function setRoute($route) {
    $this->route = $route;
    return $this;
  }

  /**
   * Current route
   * @access public
   * @return string
   */
  public function getRoute() {
    return $this->route ? $this->route : '';
  }

  /**
   * Set action that's processing now
   * @access public
   * @param string $route
   * @return $this
   */
  public function setAction($action) {
    $this->action = trim(preg_replace('|[^a-z0-9\_\-\/]+|is', '', $action), '/');
    return $this;
  }

  /**
   * Get current action
   * @access public
   * @return string
   */
  public function getAction() {
    return $this->action ? $this->action : config('default.action');
  }
}

/**
 * Класс для формирования ответа клиенту
 *
 * @final
 *  @package Core
 * @subpackage Config
 */

class Response {
  /**
   * @property array $headers Список заголовков, которые отправляются клиенту
   * @property string $body ответ клиенту, содержаший необходимый контент на выдачу
   * @property int $status код HTTP-статуса
   *
   * @property array $messages возможные статусы и сообщения HTTP-ответов
   */
  protected
  $headers  = [],
  $body     = '',
  $status   = 200;

  protected static
  $messages = [
    200 => 'OK',
    201 => 'Created',
    202 => 'Accepted',
    203 => 'Non-Authoritative Information',
    204 => 'No Content',
    205 => 'Reset Content',
    206 => 'Partial Content',

    301 => 'Moved Permanently',
    302 => 'Found',
    303 => 'See Other',
    304 => 'Not Modified',
    305 => 'Use Proxy',
    307 => 'Temporary Redirect',

    400 => 'Bad Request',
    401 => 'Unauthorized',
    402 => 'Payment Required',
    403 => 'Forbidden',
    404 => 'Not Found',
    405 => 'Method Not Allowed',
    406 => 'Not Acceptable',
    407 => 'Proxy Authentication Required',
    408 => 'Request Timeout',
    409 => 'Conflict',
    410 => 'Gone',
    411 => 'Length Required',
    412 => 'Precondition Failed',
    413 => 'Request Entity Too Large',
    414 => 'Request-URI Too Long',
    415 => 'Unsupported Media Type',
    416 => 'Requested Range Not Satisfiable',
    417 => 'Expectation Failed',

    500 => 'Internal Server Error',
    501 => 'Not Implemented',
    502 => 'Bad Gateway',
    503 => 'Service Unavailable',
    504 => 'Gateway Timeout',
    505 => 'HTTP Version Not Supported',

  ];

  /**
   * Init of new response
   * @param int $status HTTP Status of response
   * @return void
   */
  final protected function __construct($status = 200) {
    assert(is_int($status), 'Status must be integer');
    $this->status($status);
  }
  /**
   * Create new response
   * @param int $status HTTP status of response
   * @return $this
   */
  public static function create($status = 200) {
    return new static($status);
  }

  /**
   * Change HTTP status of response
   * @param int $status New HTTP status to be set
   * @return $this
   */
  public function status($status) {
    assert(in_array($status, array_keys(self::$messages)));
    if (isset(self::$messages[$status])) {
      $this->status = $status;
    }
    return $this;
  }

  /**
  * Get response body
  * @access public
  * @return string данные ответа клиенту
  */
  public function __toString( ) {
    return (string) $this->body;
  }

  /**
   * Send body to output
   * @return $this
   */
  public function sendBody() {
    echo (string) $this;
    return $this;
  }

  /**
   * Send all staff to output: headers, body and so on
   * @return $this
   */
  public function send($content = '') {
    return $this->sendHeaders()->setBody($content)->sendBody();
  }

  /**
  * Relocate user to url
  * @param string $url полный HTTP-адрес страницы для редиректа
  * @param int $code код редиректа (301 | 302)
  * @return void
  */
  public static function redirect($url, $code = 302) {
    assert(is_string($url));
    assert(in_array($code, [301, 302]));

    if ($url[0] === '/')
      $url = config('common.proto') . '://' . getenv('HTTP_HOST') . $url;

    static::create($code)
      ->header('Content-type', '')
      ->header('Location', $url)
      ->sendHeaders()
    ;
    exit;
  }

  /**
  * Reset headers stack
  * @return Response
  */
  public function flushHeaders( ) {
    $this->headers = [];
    return $this;
  }

  /**
  * Push header to stack to be sent
  * @param string $header
  * @param string $value
  * @return Response
  */
  public function header($header, $value) {
    assert(is_string($header));
    assert(is_string($value));

    $this->headers[$header] = $value;
    return $this;
  }

  /**
   * Send stacked headers to output
   * @return Response
   */
  protected function sendHeaders() {
    Cookie::send(); // This is not good but fuck it :D
    if (headers_sent()) {
      return $this;
    }
    $protocol = filter_input(INPUT_SERVER, 'SERVER_PROTOCOL') ?: 'HTTP/1.1';

    // HTTP-строка статуса
    header($protocol . ' ' . $this->status . ' ' . self::$messages[$this->status], true);

    foreach ($this->headers as $header=>$value) {
      header($header . ': ' . $value, true);
    }
    return $this;
  }

  /**
  * Set boy data to response
  * @access public
  * @param string $body
  * @return $this
  */
  public function setBody($body) {
    assert(is_string($body));

    $this->body = $body;
    return $this;
  }
}

/**
 * Class Session
 * Work with sessions
 *
 * <code>
 * Session::start();
 * Session::set('key', 'Test value');
 * Session::get('key');
 * Session::remove('key');
 * if (Session::has('key')) echo 'Found key in Session';
 * Session::regenerate();
 * </code>
 *
 * Add calculated data if key not exists
 * <code>
 * Session::add('key', function () { return time(); });
 * </code>
 *
 * Get key from session with default value
 * <code>
 * Session:get('key', 'default');
 * </code>
 */
class Session {
  /** @var Session $Instance */
  protected static $Instance = null;

  /** @var array $container */
  protected static $container = [];

  public final function __construct() {}

  public static function start() {
    session_name(config('session.name'));
    session_start();
    static::$container = &$_SESSION;
  }

  public static function id() {
    return session_id();
  }

  public static function destroy() {
    return session_destroy();
  }

  /**
   * Regenrate new session ID
   */
  public static function regenerate() {
    session_regenerate_id();
  }

  /**
   * @param string $key
   * @return bool
   */
  public static function has($key) {
    assert(is_string($key));
    return isset(static::$container[$key]);
  }

  /**
   * Add new session var if it not exists
   * @param string $key
   * @param mixed $value Can be callable function, so it executes and pushes
   * @return void
   */
  public static function add($key, $value) {
    if (!static::has($key)) {
      static::set($key, is_callable($value) ? $value() : $value);
    }
  }

  /**
   * Set new var into session
   * @param string $key
   * @param mixed $value
   * @return void
   */
  public static function set($key, $value) {
    assert(is_string($key));
    static::$container[$key] = $value;
  }

  /**
   * Remove the key from session array
   * @param string $key
   * @return bool
   */
  public static function remove($key) {
    assert(is_string($key));
    if (isset(static::$container[$key])) {
      unset(static::$container[$key]);
      return true;
    }
    return  false;
  }

  /**
   * Alias for self::remove
   * @see self::remove
   */
  public static function delete($key) {
    return static::remove($key);
  }

  /**
   * Get var with key from session array
   * @param string $key
   * @param mixed $default Return default there is no such key, set on closure
   * @return mixed
   */
  public static function get($key, $default = null) {
    if (!static::has($key) && $default && is_callable($default)) {
      $default = $default();
      static::set($key, $default);
    }
    return static::has($key) ? static::$container[$key] : $default;
  }
}

/**
 * Класс реализации представления
 *
 * @final
 * @package Core
 * @subpackage View
 *
 * <code>
 * View::create('template')->set(['test_var' => 'test_val'])->render();
 * </code>
 */
class View {
  const VAR_PTRN = '\!?[a-z\_]{1}[a-z0-9\.\_]*';

  /**
   * @property array $data массив переменных, которые использует подключаемый шаблон
   * @property string $body обработанные и готовые данные для отдачи их клиенту
   */
  protected $data = [];
  protected $routes = [];
  protected $body = null;
  protected $source_dir = null;
  protected $compile_dir = null;
  protected $prefix = 'c';
  protected $output_filters = [];
  protected $compilers = [];

  protected static $filter_funcs = [
    'html' => 'htmlspecialchars',
    'url'  => 'rawurlencode',
    'json' => 'json_encode',
    'upper' => 'strtoupper',
    'lower' => 'strtolower',
    'raw'  => '',
  ];

  /** @var string $template_extension */
  protected $template_extension = 'tpl';

  /** @var array $block_path */
  protected $block_path = [];

  /**
   * Финальный приватный конструктор, через него создания вида закрыто
   *
   * @see self::create
   */
  final protected function __construct() {
    $this->routes = [config('default.action')];

    // Setup default settings
    $this->template_extension = config('view.template_extension');
    $this->source_dir = config('view.source_dir');
    $this->compile_dir = config('view.compile_dir');
  }

  public function configure(array $config) {
    foreach ($config as $prop => $val) {
      if (property_exists($this, $prop)) {
        $this->$prop = $val;
      }
    }
    return $this;
  }

  /**
   * @param string $template
   * @return View
   */
  public function prepend($template) {
    array_unshift($this->routes, $template);
    return $this;
  }

  /**
   * @param string $template
   * @return View
   */
  public function append($template) {
    $this->routes[] = $template;
    return $this;
  }

  /**
   * Создание нового объекта вида
   *
   * @static
   * @access public
   * @param string $route Список всех роутов в нужной последовательности для сборки
   * @return View
   */
  public static function create(...$routes) {
    $View = new static;
    $View->routes = $routes;
    return $View;
  }

  public static function fromString($content) {
    $View = new static;
    $View->body = $content;
    return $View;
  }

  /**
   * Получает уже обработанные и готовые данные для вывода функцией self::render()
   *
   * @access public
   * @return string
   */
  public function __toString( ) {
    return $this->getBody();
  }

  public function addOutputFilter(Callable $filter) {
    $this->output_filters = $filter;
    return $this;
  }

  protected function getBody() {
    $body = $this->body;
    foreach ($this->output_filters as $filter) {
      $body = $filter($body);
    }
    return $body;
  }

  /**
   * Прикрепление массива как разных переменных в шаблон
   *
   * @access public
   * @param array $data
   * @return View
   */
  public function set(array $data) {
    $this->data = $data;
    return $this;
  }

  public function assign($key, $val = null) {
    assert(in_array(gettype($key), ["string", "array"]));
    if (is_string($key)) {
      $this->data[$key] = $val;
    } elseif (is_array($key)) {
      $this->data = array_merge($this->data, $key);
    }
    return $this;
  }

  public function &access($key) {
    return $this->data[$key];
  }

  /**
   * Обработчик блочных элементов скомпилированном шаблоне
   *
   * @param string $key
   *   Имя переменной
   * @param mixed $param
   *   Сам параметр, его значение
   * @param mixed $item
   *   Текущий айтем, т.к. возможно блок является вложенным и нужно передать текущий
   *   обходной элемент, если блок не является массивом
   * @param Closure $block
   *   Скомпилированный код, которые отображается внутри блока
   * @return View
   */
  protected function block($key, $param, $item, Closure $block) {
    assert(is_string($key));

    static $arrays = [];
    $arrays[$key] = is_array($param);
    if ($arrays[$key] && is_int(key($param))) {
      $last = sizeof($param) - 1;
      $i = 0;
      foreach ($param as $k => $value) {
        if (!is_array($value)) {
          $value = ['parent' => $item, 'this' => $value];
        }

        $value['global']     = &$this->data;
        $value['first']      = $i === 0;
        $value['last']       = $i === $last;
        $value['even']       = $i % 2 ?  true : false;
        $value['odd']        = !$value['even'];
        $value['iteration']  = ++$i;
        $block($value);
      }
    } elseif ($param) {
      if ($arrays[$key]) {
        $item   = $param + ['global' => &$this->data, 'parent' => $item];
        $block($item);
        $item = $item['parent'];
      } else $block($item);

    }
    return $this;
  }


  protected static function chunkVar($v, $container = '$item') {
    $var = '';
    foreach (explode('.', $v) as $p) {
      $var .= ($var ? '' : $container) . '[\'' . $p . '\']';
    }
    return $var;
  }


  protected static function chunkVarExists($v, $container = '$item') {
    $parts = explode('.', $v);
    $sz = sizeof($parts);
    $var = '';
    $i = 0;
    foreach ($parts as $p) {
      ++$i;
      if ($i !== $sz) {
        $var .= ($var ? '' : $container) . '[\'' . $p . '\']';
      }
    }
    $array = ($var ?: $container);
    return 'isset(' . $array . ') && array_key_exists(\'' . $p . '\', ' . $array . ')';
  }

  protected static function chunkParseParams($str) {
    $str = trim($str);
    if (!$str)
      return '';

    $code = '';
    foreach (array_map('trim', explode(' ', $str)) as $item) {
      list($key, $val) = array_map('trim', explode('=', $item));
      $code .= '<?php ' . static::chunkVar($key) . ' = ' . static::chunkVar($val) . '; ?>';
    }
    return $code;
  }

  /**
   * @param string $str
   * @return string
   */
  protected static function chunkTransformVars($str) {
    $filter_ptrn = implode(
      '|' ,
      array_map(
        function($v) {
          return '\:' . $v;
        },
        array_keys(static::$filter_funcs)
      )
    );

    return preg_replace_callback(
      '#\{(' . static::VAR_PTRN . ')(' . $filter_ptrn . ')?\}#ium',
      function ($matches) {
        $filter = 'raw';
        if (isset($matches[2])) {
          $filter = substr($matches[2], 1);
        }

        return '<?php if (isset(' . ($v = static::chunkVar($matches[1], '$item')) . ')) {'
        . 'echo ' . static::$filter_funcs[$filter] . '(' . $v . ');'
        . '} ?>';
      },
      $str
    );
  }

  /**
   * Transform one line blocks to closed blocks
   * @param string $str
   * @return string
   */
  protected function chunkCloseBlocks($str) {
    $line_block = '#\{(' . static::VAR_PTRN . ')\:\}(.+)$#ium';

    // Могут быть вложенные
    while (preg_match($line_block, $str) > 0) {
      $str = preg_replace($line_block, '{$1}' . PHP_EOL . '$2' . PHP_EOL . '{/$1}', $str);
    }

    return $str;
  }

  /**
   * @param string $str
   * @return string
   */
  protected function chunkCompileBlocks($str) {
    return preg_replace_callback(
      '#\{(' . static::VAR_PTRN . ')\}(.+?){\/\\1}#ius',
      function ($m) {
        // Oh Shit so magic :)
        $this->block_path[] = $m[1];
        $compiled  = static::chunkTransformVars(static::chunkCompileBlocks($m[2]));
        array_pop($this->block_path);

        // Если стоит отрицание
        $denial = false;
        $key    = $m[1];

        if (0 === strpos($m[1], '!')) {
          $key = substr($m[1], 1);
        }

        if (strlen($m[1]) !== strlen($key)) {
          $denial = true;
        }

        return
          '<?php $param = ' . static::chunkVarExists($m[1], '$item') . ' ? ' . static::chunkVar($m[1], '$item') . ' : null;'
        . ($denial ? ' if (!isset($param)) $param = !( ' . static::chunkVarExists($key, '$item') . ' ? ' . static::chunkVar($key, '$item') . ' : null);' : '') // Блок с тегом отрицанием (no_ | not_) только если не существует переменной как таковой
        . '$this->block(\'' . $key . '\', $param, $item, function ($item) { ?>'
          . $compiled
        . '<?php }); ?>';
      },
      $str
    );
  }

  /**
   * Optimize output of compiled chunk if needed
   * @param string $str
   * @return string
   */
  protected function chunkMinify($str) {
    // Remove tabs and merge into single line
    if (config('view.merge_lines')) {
      $str = preg_replace(['#^\s+#ium', "|\s*\r?\n|ius"], '', $str);
    }

    // Remove comments
    if (config('view.strip_comments')) {
      $str = preg_replace('/\<\!\-\-.+?\-\-\>/is', '', $str);
    }

    return $str;
  }

  /**
   * Компиляция примитивов шаблона
   *
   * @param string $route
   *   Роут шаблона для компиляции
   * @return string
   *   Имя скомпилированного файла
   */
  protected function compileChunk($route) {
    $source_file = $this->getSourceFile($route);
    $file_c = $this->getCompiledFile([$route]);
    if (!App::$debug && is_file($file_c)) {
      return $file_c;
    }

    $str = file_get_contents($source_file);
    // Do precompile by custom compiler to make it possible to change vars after
    $compilers = array_merge($this->compilers[$route] ?? [], $this->compilers['*'] ?? []);
    if ($compilers) {
      foreach ($compilers as $compiler) {
        $str = $compiler($str, $route);
      }
    }

    $str = $this->chunkCloseBlocks($str);

    // Компиляция блоков
    $str = $this->chunkCompileBlocks($str);

    $str = $this->chunkMinify($str);

    // Замена подключений файлов
    $str = preg_replace_callback('#\{\>([a-z\_0-9\/]+)(.*?)\}#ium', function ($matches) {
      return static::chunkParseParams($matches[2]) . $this->getChunkContent($matches[1]);
    }, $str);

    // Переменные: {array.index}
    $str = static::chunkTransformVars($str);

    file_put_contents($file_c, $str, LOCK_EX);
    return $file_c;
  }

  /**
   * Компиляция всех чанков и получение результата
   *
   * @return View
   */
  protected function compile() {
    $file_c = $this->getCompiledFile();
    if (App::$debug || !is_file($file_c)) {
      $content = [];
      foreach ($this->routes as $template) {
        $content[] = $this->getChunkContent($template);
      }

      // Init global context
      array_unshift($content, '<?php $item = &$this->data; ?>');
      file_put_contents($file_c, implode($content), LOCK_EX);
    }
    include $file_c;
    return $this;
  }

  protected function getChunkContent($template) {
    return file_get_contents($this->compileChunk($template));
  }

  public function addCompiler(Callable $compiler, $template = '*') {
    $this->compilers[$template][] = $compiler;
    return $this;
  }

  protected function getSourceFile($route) {
    assert(is_string($this->source_dir) && is_dir($this->source_dir));
    assert(is_string($this->template_extension) && isset($this->template_extension[0]));

    return $this->source_dir . '/' . $route . '.' . $this->template_extension;
  }

  protected function getCompiledFile($routes = []) {
    assert(is_string($this->compile_dir) && is_dir($this->compile_dir) && is_writable($this->compile_dir));
    return $this->compile_dir . '/view-' . $this->prefix . '-' . md5($this->source_dir . ':' . implode(':', $routes ?: $this->routes)) . '.tplc';
  }

  /**
   * Рендеринг и подготовка данных шаблона на вывод
   *
   * @access public
   * @param bool $quiet Quiet mode render empty string if no template found
   * @return View
   *   Записывает результат во внутреннюю переменную $body
   *   и возвращает ссылку на объект
   */
  public function render($quiet = false) {
    if (isset($this->body)) {
      return $this;
    }

    try {
      ob_start();
      $this->compile();
      $this->body = ob_get_clean();
    } catch (Exception $e) {
      if ($quiet) {
        $this->body = '';
      } else {
        throw $e;
      }
    }
    return $this;
  }

  public static function flush() {
    system('for file in `find ' . escapeshellarg(config('view.compile_dir')) . ' -name \'view-*\'`; do rm -f $file; done');
  }
}


/**
 * Config workout for whole app
 * @param  string $param Param using dot for separate packages
 * @return mixed
 */
function config($param) {
  assert(is_string($param));

  static $config = [];
  if (!$config) {
    $config = include getenv('CONFIG_DIR') . '/config.php';
  }

  return $config[$param];
}

/**
 * Typify var to special type
 * @package Core
 * @param string $var Reference to the var that should be typified
 * @param string $type [int|integer, uint|uinteger, double|float, udboule|ufloat, bool|boolean, array, string]
 * @return void
 *
 * <code>
 * $var = '1'; // string(1) "1"
 * typify($var, $type);
 * var_dump($var); // int(1)
 * </code>
 */
function typify(&$var, $type) {
  switch ($type) {
    case 'int':
    case 'integer':
      $var = (int) $var;
      break;
    case 'uinteger':
    case 'uint':
      $var = (int) $var;
      if ($var < 0)
        $var = 0;
      break;
    case 'double':
    case 'float':
      $var = (float) $var;
      break;
    case 'udouble':
    case 'ufloat':
      $var = (float) $var;
      if ($var < 0)
        $var = 0.0;
      break;
    case 'boolean':
    case 'bool':
      $var = (in_array($var, ['no', 'none', 'false', 'off'], true) ? false : (bool) $var);
      break;
    case 'array':
      $var = $var ? (array) $var : [];
      break;
    case 'string':
      $var = (string) $var;
      break;
  }
  return;
}

/**
 * Triggered events
 * @param string $event
 * @param array $payload Дополнительные данные для манипуляции
 * @return mixed
 */
function trigger_event($event, array $payload = []) {
  assert(is_string($event));

  static $map;
  if (!isset($map)) {
    $map = App::getJSON(config('common.trigger_map_file'));
  }

  if (isset($map[$event])) {
    array_walk($map[$event], function ($_file) use ($payload) {
      extract(
        Input::extractTypified(
          App::getImportVarsArgs($_file, config('common.trigger_param_file')),
          function ($key, $default = null) use ($payload) {
            return isset($payload[$key]) ? $payload[$key] : $default;
          }
        )
      );
      include $_file;
    });
  }
}

/**
 * Get short name for full qualified class name
 * @param string $class The name of class with namespaces
 * @return string
 */
function get_class_name($class) {
  return (new ReflectionClass($class))->getShortName();
}

// Missed functions for large integers for BCmath
function bchexdec($hex) {
  $dec = 0;
  $len = strlen($hex);
  for ($i = 1; $i <= $len; $i++) {
    $dec = bcadd($dec, bcmul(strval(hexdec($hex[$i - 1])), bcpow('16', strval($len - $i))));
  }
  return $dec;
}

function bcdechex($dec) {
  $hex = '';
  do {
    $last = bcmod($dec, 16);
    $hex = dechex($last).$hex;
    $dec = bcdiv(bcsub($dec, $last), 16);
  } while($dec > 0);
  return $hex;
}

function bench($level = 0, $txt = null) {
  static $t = [], $r = [];
  if ($level === true) {
    foreach ($r as $txt => $vals) {
      echo $txt . ': ' . sprintf('%f', array_sum($vals) / sizeof($vals)) . 's' . PHP_EOL;
    }
    $t = $r = [];
    return;
  }
  $n = microtime(true);

  if ($txt && !isset($r[$txt])) {
    $r[$txt] = [];
  }

  if ($txt && isset($t[$level])) {
    $r[$txt][] = $n - $t[$level][sizeof($t[$level]) - 1];
  }
  $t[$level][] = $n;
}

