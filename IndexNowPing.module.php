<?php

namespace ProcessWire;

/**
 * IndexNowPing — submits changed pages to IndexNow (Bing, Yandex, Seznam,
 * Naver, Yep).
 *
 * Configuration precedence (resolveConfig(), cached per request):
 *   1. `$config->indexNow === false` — kill-switch, wins over everything.
 *   2. Module settings from the config screen (DB).
 *   3. `$config->indexNow` array from site/config.php (field defaults).
 *   4. Built-in defaults (endpoint, httpHosts[0]).
 *
 * Page hooks only buffer URLs; one submit runs at request shutdown, so
 * scripts that call $pages->saveField() per field (importers, migrations)
 * cannot produce one POST per field.
 */
class IndexNowPing extends WireData implements Module, ConfigurableModule
{
  private const DEFAULT_ENDPOINT = 'https://api.indexnow.org/indexnow';

  private const MAX_URLS_PER_REQUEST = 10000;

  /** @var array|null Merged config, cached for the current request. */
  private $resolvedConfig = null;

  /** @var array<string,bool> Normalized URLs queued during this request. */
  private $queue = [];

  /** @var bool Whether the shutdown flush handler is already registered. */
  private $flushRegistered = false;

  /** @var bool Re-entrancy guard for flush(). */
  private $flushing = false;

  public static function getModuleInfo()
  {
    return [
      'title' => 'IndexNow Ping',
      'version' => '0.1.0',
      'summary' => 'Submit new/changed/deleted URLs to IndexNow (Bing, Yandex & Co.)',
      'autoload' => true,
      'icon' => 'bolt',
      'requires' => 'ProcessWire>=3.0, PHP>=8.0',
      'singular' => true,
      'author' => 'webmanufaktur',
      'href' => 'https://github.com/webmanufaktur/IndexNowPing',
    ];
  }

  public function init()
  {
    $this->ensureKeyFile();

    $cfg = $this->resolveConfig();
    if ($cfg['killswitch'] || !$cfg['enabled']) {
      return;
    }

    $this->addHookAfter('Pages::saved', $this, 'hookSaved');
    $this->addHookAfter('Pages::deleted', $this, 'hookDeleted');
    $this->addHookAfter('Pages::moved', $this, 'hookMoved');
    $this->addHookBefore('Pages::trash', $this, 'hookTrash');
  }

  public function ___install()
  {
    $config = $this->wire('config');
    $raw = $config->indexNow;
    $rawArr = is_array($raw) ? $raw : [];
    $httpHosts = $config->httpHosts;

    $data = [
      'enabled' => array_key_exists('enabled', $rawArr) ? (bool) $rawArr['enabled'] : false,
      'key' => isset($rawArr['key']) ? (string) $rawArr['key'] : '',
      'endpoint' => !empty($rawArr['endpoint']) ? (string) $rawArr['endpoint'] : self::DEFAULT_ENDPOINT,
      'host' => !empty($rawArr['host'])
        ? (string) $rawArr['host']
        : (is_array($httpHosts) && count($httpHosts) ? (string) reset($httpHosts) : ''),
      'templates' => [],
      'logEnabled' => true,
    ];

    $this->setArray($data);
    $this->resolvedConfig = null;
    $this->wire('modules')->saveConfig($this, $data);
    $this->ensureKeyFile();
  }

  /**
   * Resolve the effective configuration. Cached per request.
   *
   * @return array{enabled:bool,key:string,endpoint:string,host:string,templates:array,logEnabled:bool,killswitch:bool}
   */
  public function resolveConfig(): array
  {
    if ($this->resolvedConfig !== null) {
      return $this->resolvedConfig;
    }

    $config = $this->wire('config');
    $raw = $config->indexNow;
    $rawArr = is_array($raw) ? $raw : [];
    $killswitch = ($raw === false);

    $key = (string) $this->pick('key', '', $rawArr);
    $endpoint = (string) $this->pick('endpoint', self::DEFAULT_ENDPOINT, $rawArr);
    $host = trim((string) $this->pick('host', '', $rawArr));
    $templates = $this->pick('templates', [], $rawArr);
    $enabled = (bool) $this->pick('enabled', false, $rawArr);
    $logEnabled = (bool) $this->pick('logEnabled', true, $rawArr);

    if ($host === '') {
      $httpHosts = $config->httpHosts;
      if (is_array($httpHosts) && count($httpHosts)) {
        $host = (string) reset($httpHosts);
      }
    }
    $host = preg_replace('#^https?://#i', '', $host);
    $host = rtrim((string) $host, '/');

    // No template lookups here: init() runs on every request and must stay cheap.
    // An empty template list is valid and means "observe nothing" — there are
    // deliberately no built-in template defaults to surprise anyone with.
    $existing = [];
    foreach ((array) $templates as $name) {
      if (is_string($name) && $name !== '') {
        $existing[] = $name;
      }
    }

    $this->resolvedConfig = [
      'enabled' => $enabled,
      'key' => $key,
      'endpoint' => $endpoint !== '' ? $endpoint : self::DEFAULT_ENDPOINT,
      'host' => $host,
      'templates' => array_values(array_unique($existing)),
      'logEnabled' => $logEnabled,
      'killswitch' => $killswitch,
    ];

    return $this->resolvedConfig;
  }

  /**
   * Ensure `<key>.txt` exists in the project root and contains exactly the key.
   *
   * Called from init() (quick check, no write when everything is in place) and
   * from the config screen. When the key changed, the previous key file is
   * deleted; no other file in the root is touched.
   */
  public function ensureKeyFile(?string $key = null): void
  {
    $cfg = $this->resolveConfig();
    if ($cfg['killswitch']) {
      return;
    }

    $key = $key !== null ? trim($key) : (string) $cfg['key'];
    if ($key === '' || !$this->isValidKey($key)) {
      return;
    }

    $root = rtrim((string) $this->wire('config')->paths->root, '/') . '/';
    $old = trim((string) $this->get('last_key_written'));

    if ($old !== '' && $old !== $key && $this->isValidKey($old)) {
      $oldPath = $root . $old . '.txt';
      if (is_file($oldPath)) {
        @unlink($oldPath);
      }
    }

    $path = $root . $key . '.txt';
    $ok = true;

    if (!is_file($path) || file_get_contents($path) !== $key) {
      $ok = @file_put_contents($path, $key, LOCK_EX) !== false;
      if (!$ok) {
        $this->logLine([
          'context' => 'key-file',
          'error' => 'unable to write key file',
          'path' => $path,
        ]);
      }
    }

    if ($ok && $old !== $key) {
      $this->set('last_key_written', $key);
      $this->wire('modules')->saveConfig($this, 'last_key_written', $key);
    }
  }

  public function hookSaved($event): void
  {
    $cfg = $this->resolveConfig();
    if ($cfg['killswitch'] || !$cfg['enabled']) {
      return;
    }

    $page = $event->arguments(0);
    if (!$page instanceof Page || !$page->id || !$page->template) {
      return;
    }
    if ($page->isTrash() || $page->isUnpublished() || $page->isHidden()) {
      return;
    }
    if (!in_array($page->template->name, $cfg['templates'], true)) {
      return;
    }
    if ($this->isNoindex($page)) {
      return;
    }

    $this->enqueue($page->httpUrl(), $cfg);
  }

  public function hookDeleted($event): void
  {
    $cfg = $this->resolveConfig();
    if ($cfg['killswitch'] || !$cfg['enabled']) {
      return;
    }

    $page = $event->arguments(0);
    if (!$page instanceof Page || !$page->id || !$page->template) {
      return;
    }
    if (!in_array($page->template->name, $cfg['templates'], true)) {
      return;
    }

    $this->enqueue($page->httpUrl(), $cfg);
  }

  public function hookMoved($event): void
  {
    $cfg = $this->resolveConfig();
    if ($cfg['killswitch'] || !$cfg['enabled']) {
      return;
    }

    $page = $event->arguments(0);
    if (!$page instanceof Page || !$page->id || !$page->template) {
      return;
    }
    if (!in_array($page->template->name, $cfg['templates'], true)) {
      return;
    }
    // Trash is handled by hookTrash() before the path changes.
    if ($page->isTrash()) {
      return;
    }

    $oldParent = $page->parentPrevious;
    if ($oldParent instanceof Page && $oldParent->id && !$oldParent->isTrash()) {
      $oldUrl = rtrim($oldParent->httpUrl(), '/') . '/' . $page->name . '/';
      $this->enqueue($oldUrl, $cfg);
    }

    if (!$page->isUnpublished() && !$page->isHidden() && !$this->isNoindex($page)) {
      $this->enqueue($page->httpUrl(), $cfg);
    }
  }

  public function hookTrash($event): void
  {
    $cfg = $this->resolveConfig();
    if ($cfg['killswitch'] || !$cfg['enabled']) {
      return;
    }

    $page = $event->arguments(0);
    if (!$page instanceof Page || !$page->id || !$page->template) {
      return;
    }
    if (!in_array($page->template->name, $cfg['templates'], true)) {
      return;
    }

    // Runs before Pages::trash: the original path is still intact here.
    $this->enqueue($page->httpUrl(), $cfg);
  }

  /**
   * Submit URLs to IndexNow.
   *
   * @param string[] $urls
   * @param string $context Log context, e.g. 'hook', 'batch', 'manual'.
   * @return array HTTP status codes per chunk, or ['skipped' => reason].
   */
  public function submit(array $urls, string $context = 'manual'): array
  {
    $cfg = $this->resolveConfig();

    if ($cfg['killswitch']) {
      return ['skipped' => 'kill-switch'];
    }
    if (!$cfg['enabled']) {
      return ['skipped' => 'disabled'];
    }
    if ($cfg['host'] === '' || $cfg['key'] === '' || $cfg['endpoint'] === '') {
      return ['skipped' => 'missing-config'];
    }

    $httpHost = strtolower((string) $this->wire('config')->httpHost);
    if ($httpHost !== strtolower($cfg['host'])) {
      return ['skipped' => 'host-mismatch'];
    }

    $clean = [];
    foreach ($urls as $url) {
      $normalized = $this->normalizeUrl($url, $cfg['host']);
      if ($normalized !== '') {
        $clean[$normalized] = true;
      }
    }
    $clean = array_keys($clean);
    if (!$clean) {
      return ['skipped' => 'no-urls'];
    }

    $payloadBase = [
      'host' => $cfg['host'],
      'key' => $cfg['key'],
      'keyLocation' => "https://{$cfg['host']}/{$cfg['key']}.txt",
    ];

    $results = [];

    // IndexNow accepts at most 10,000 URLs per request.
    foreach (array_chunk($clean, self::MAX_URLS_PER_REQUEST) as $i => $chunk) {
      $payload = json_encode(
        $payloadBase + ['urlList' => $chunk],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
      );

      if ($payload === false) {
        $results[] = 0;
        if ($cfg['logEnabled']) {
          $this->logLine([
            'context' => $context,
            'chunk' => $i,
            'count' => count($chunk),
            'status' => 0,
            'error' => 'json_encode failed',
            'urls' => array_slice($chunk, 0, 3),
          ]);
        }
        continue;
      }

      $status = 0;
      $body = '';

      try {
        // WireHttp::post($url, $data, array $options) — $data may be a raw JSON string.
        $http = new WireHttp();
        $response = $http->post($cfg['endpoint'], $payload, [
          'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
          'timeout' => 5,
        ]);
        $status = (int) $http->getHttpCode();
        $body = $response === false ? (string) $http->getError() : (string) $response;
      } catch (\Throwable $e) {
        $body = $e->getMessage();
      }

      if ($cfg['logEnabled']) {
        $this->logLine([
          'context' => $context,
          'chunk' => $i,
          'count' => count($chunk),
          'status' => $status,
          'body' => mb_substr($body, 0, 500),
          'urls' => array_slice($chunk, 0, 3),
        ]);
      }

      $results[] = $status;
    }

    return $results;
  }

  /**
   * Shutdown handler: submit everything buffered during this request.
   */
  public function flush(): void
  {
    if ($this->flushing || !$this->queue) {
      return;
    }

    $this->flushing = true;
    $urls = array_keys($this->queue);
    $this->queue = [];

    try {
      $this->submit($urls, 'hook');
    } finally {
      $this->flushing = false;
    }
  }

  public function getModuleConfigInputfields(array $data)
  {
    $modules = $this->wire('modules');
    $wrapper = $this->wire(new InputfieldWrapper());
    $config = $this->wire('config');
    $raw = $config->indexNow;
    $rawArr = is_array($raw) ? $raw : [];
    $httpHosts = $config->httpHosts;
    $fallbackHost = is_array($httpHosts) && count($httpHosts) ? (string) reset($httpHosts) : '';
    $rawHost = !empty($rawArr['host']) ? (string) $rawArr['host'] : $fallbackHost;
    $rawEndpoint = !empty($rawArr['endpoint']) ? (string) $rawArr['endpoint'] : self::DEFAULT_ENDPOINT;
    $rawKey = isset($rawArr['key']) ? (string) $rawArr['key'] : '';

    // Write/refresh the key file for the currently saved key before reporting health.
    $this->ensureKeyFile();

    /** @var InputfieldCheckbox $field */
    $field = $modules->get('InputfieldCheckbox');
    $field->name = 'enabled';
    $field->label = 'Enabled';
    $field->checked = !empty($data['enabled']);
    $field->description = 'Main switch. A kill-switch in site/config-dev.php (`$config->indexNow = false;`) always wins over this setting.';
    $wrapper->append($field);

    /** @var InputfieldText $field */
    $field = $modules->get('InputfieldText');
    $field->name = 'key';
    $field->label = 'IndexNow key';
    $field->value = isset($data['key']) ? (string) $data['key'] : '';
    $field->attr('style', 'font-family: monospace;');
    $field->description = 'The key itself is public. Effective default from site/config.php: '
      . ($rawKey !== '' ? $rawKey : '(none)')
      . '. The module writes and rotates /<key>.txt in the webroot automatically.';
    $wrapper->append($field);

    /** @var InputfieldText $field */
    $field = $modules->get('InputfieldText');
    $field->name = 'endpoint';
    $field->label = 'Endpoint';
    $field->value = isset($data['endpoint']) ? (string) $data['endpoint'] : '';
    $field->description = 'Leave empty to use the effective default: ' . $rawEndpoint;
    $wrapper->append($field);

    /** @var InputfieldText $field */
    $field = $modules->get('InputfieldText');
    $field->name = 'host';
    $field->label = 'Canonical host';
    $field->value = isset($data['host']) ? (string) $data['host'] : '';
    $field->description = 'Canonical host including `www.`, no scheme. Leave empty to use the effective default: '
      . ($rawHost !== '' ? $rawHost : '(none)');
    $wrapper->append($field);

    /** @var InputfieldAsmSelect $field */
    $field = $modules->get('InputfieldAsmSelect');
    $field->name = 'templates';
    $field->label = 'Observed templates';
    $field->description = 'Only pages using these templates are submitted on save/delete/move/trash. Select at least one template — an empty list observes nothing.';
    $options = [];
    foreach ($this->wire('templates') as $template) {
      if ($template->flags & Template::flagSystem) {
        continue;
      }
      $options[$template->name] = $template->name;
    }
    asort($options);
    $field->addOptions($options);
    $selected = isset($data['templates']) && is_array($data['templates']) ? $data['templates'] : [];
    $field->value = array_values(array_intersect(array_keys($options), $selected));
    $wrapper->append($field);

    /** @var InputfieldCheckbox $field */
    $field = $modules->get('InputfieldCheckbox');
    $field->name = 'logEnabled';
    $field->label = 'Write log entries';
    $field->checked = array_key_exists('logEnabled', $data) ? (bool) $data['logEnabled'] : true;
    $field->description = 'Logs one JSON line per request chunk to site/assets/logs/indexnow.txt.';
    $wrapper->append($field);

    // Read-only status cells.
    $cfg = $this->resolveConfig();
    $sanitizer = $this->wire('sanitizer');
    $root = rtrim((string) $config->paths->root, '/') . '/';

    if ($cfg['killswitch']) {
      $status = 'KILL-SWITCHED';
      $statusNote = '`$config->indexNow === false` — all submissions are disabled, module settings are ignored.';
    } elseif ($cfg['enabled']) {
      $status = 'enabled';
      $statusNote = 'Effective host: ' . $cfg['host'];
    } else {
      $status = 'disabled';
      $statusNote = 'Enable the module above to submit URLs.';
    }

    $statusHtml = '<p><strong>Effective status:</strong> ' . $sanitizer->entities($status)
      . '<br><span class="detail">' . $sanitizer->entities($statusNote) . '</span></p>';

    /** @var InputfieldMarkup $markup */
    $markup = $modules->get('InputfieldMarkup');
    $markup->name = 'indexnow_status';
    $markup->label = 'Status';
    $markup->value = $statusHtml;
    $wrapper->append($markup);

    $keyPath = $cfg['key'] !== '' ? $root . $cfg['key'] . '.txt' : '';
    $keyExists = $keyPath !== '' && is_file($keyPath);
    $keyMatches = $keyExists && file_get_contents($keyPath) === $cfg['key'];
    $keyBytes = $keyExists ? filesize($keyPath) : 0;

    /** @var InputfieldMarkup $markup */
    $markup = $modules->get('InputfieldMarkup');
    $markup->name = 'indexnow_keyfile';
    $markup->label = 'Key file';
    $markup->value = '<p><code>'
      . $sanitizer->entities($keyPath !== '' ? $keyPath : '(no key configured)')
      . '</code><br><span class="detail">exists: ' . ($keyExists ? 'yes' : 'no')
      . ' · content matches key: ' . ($keyMatches ? 'yes' : 'no')
      . ' · size: ' . (int) $keyBytes . ' bytes</span></p>';
    $wrapper->append($markup);

    $logHtml = '<p><code>'
      . $sanitizer->entities($config->paths->logs . 'indexnow.txt') . '</code></p>';
    $logLines = $this->tailLines($config->paths->logs . 'indexnow.txt', 3);
    if ($logLines) {
      $logHtml .= '<pre style="white-space: pre-wrap; max-height: 8em; overflow: auto;">'
        . $sanitizer->entities(implode("\n", $logLines)) . '</pre>';
    } else {
      $logHtml .= '<p class="detail">No log entries yet.</p>';
    }

    /** @var InputfieldMarkup $markup */
    $markup = $modules->get('InputfieldMarkup');
    $markup->name = 'indexnow_log';
    $markup->label = 'Last log entries';
    $markup->value = $logHtml;
    $wrapper->append($markup);

    return $wrapper;
  }

  /**
   * Pick a value from module settings first, then the config.php array.
   *
   * Module values count as set unless they are null/'' (arrays: empty). For the
   * boolean fields an explicit false therefore wins over config.php — the
   * config screen must be able to disable the module.
   *
   * @param string $key
   * @param mixed $builtin Stage-4 fallback.
   * @param array $rawArr `$config->indexNow` array (stage 3).
   * @return mixed
   */
  private function pick(string $key, $builtin, array $rawArr)
  {
    foreach ([$this->data, $rawArr] as $source) {
      if (!is_array($source) || !array_key_exists($key, $source)) {
        continue;
      }
      $value = $source[$key];

      if ($key === 'enabled' || $key === 'logEnabled') {
        if ($value !== null && $value !== '') {
          return (bool) $value;
        }
        continue;
      }

      if (is_array($value)) {
        if (count($value)) {
          return $value;
        }
        continue;
      }

      if ($value !== null && $value !== '') {
        return $value;
      }
    }

    return $builtin;
  }

  private function enqueue($url, array $cfg): void
  {
    $normalized = $this->normalizeUrl($url, $cfg['host']);
    if ($normalized === '') {
      return;
    }

    $this->queue[$normalized] = true;

    if (!$this->flushRegistered) {
      $this->flushRegistered = true;
      register_shutdown_function([$this, 'flush']);
    }
  }

  /**
   * Force the canonical origin while keeping the path.
   *
   * httpUrl() returns http:// in CLI; the old own-origin filter dropped those
   * URLs entirely. Normalizing first fixes that for hooks and batch alike.
   */
  private function normalizeUrl($url, string $host): string
  {
    if (!is_string($url) || trim($url) === '' || $host === '') {
      return '';
    }

    $url = trim($url);
    $url = (string) preg_replace('#^https?://[^/]+#i', "https://{$host}", $url);

    // Safety net: only our own origin.
    if (!str_starts_with($url, "https://{$host}/")) {
      return '';
    }

    return $url;
  }

  private function isValidKey(string $key): bool
  {
    return (bool) preg_match('/^[A-Za-z0-9-]{8,128}$/', $key);
  }

  private function isNoindex(Page $page): bool
  {
    if (!$page->hasField('seo_robots')) {
      return false;
    }

    // FieldtypeOptions: read via getUnformatted() — the formatted value's
    // ->title can be NULL in the web context, so never trust truthiness here.
    $value = $page->getUnformatted('seo_robots');
    $robots = is_object($value) ? (string) $value->title : (string) $value;

    return stripos($robots, 'noindex') !== false;
  }

  private function logLine(array $data): void
  {
    $data = array_merge(['ts' => date('c')], $data);
    $this->wire('log')->save(
      'indexnow',
      json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
  }

  /**
   * @return string[] Last $lines lines of $file, or [] when unusable.
   */
  private function tailLines(string $file, int $lines = 3): array
  {
    if (!is_file($file) || !is_readable($file)) {
      return [];
    }

    $fp = @fopen($file, 'rb');
    if (!$fp) {
      return [];
    }

    fseek($fp, 0, SEEK_END);
    $size = (int) ftell($fp);
    fseek($fp, max(0, $size - 16384));
    $data = (string) stream_get_contents($fp);
    fclose($fp);

    if (trim($data) === '') {
      return [];
    }

    $all = preg_split('/\r?\n/', trim($data));

    return array_slice($all, -$lines);
  }
}
