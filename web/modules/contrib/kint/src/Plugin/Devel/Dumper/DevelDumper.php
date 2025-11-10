<?php

declare(strict_types=1);

namespace Drupal\kint\Plugin\Devel\Dumper;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\devel\DevelDumperBase;
use Kint\FacadeInterface;
use Kint\Kint;
use Kint\Utils;
use Kint\Value\Context\BaseContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Kint dumper plugin.
 *
 * @DevelDumper(
 *   id = "kint",
 *   label = @Translation("Kint"),
 *   description = @Translation("Wrapper for <a href='https://github.com/kint-php/kint'>Kint</a> debugging tool."),
 * )
 */
class DevelDumper extends DevelDumperBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param mixed[] $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('config.factory')->get('kint.settings'),
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  /**
   * Constructs a new \Drupal\kint\Plugin\Devel\Dumper\DevelDumper object.
   *
   * @param \Drupal\Core\Config\Config $kintConfig
   *   The kint module configuration.
   * @param mixed[] $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   */
  public function __construct(protected Config $kintConfig, array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    Kint::$aliases = array_unique(
      Utils::normalizeAliases([...Kint::$aliases, ...$this->getInternalFunctions()]),
      SORT_REGULAR
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function checkRequirements(): bool {
    return \class_exists(Kint::class, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function export(mixed $input, ?string $name = NULL): MarkupInterface|string {
    $statics = Kint::getStatics();
    $statics['return'] = TRUE;
    $statics['enabled_mode'] = TRUE;
    $kint_instance = Kint::createFromStatics($statics);

    if (!$kint_instance) {
      return '';
    }

    $args = NULL === $name ? [$input] : [];

    $kint_instance->setStatesFromStatics($statics);
    $call_info = Kint::getCallInfo(Kint::$aliases, \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), $args);
    assert(is_null($call_info['callee']) || is_array($call_info['callee']));
    $kint_instance->setStatesFromCallInfo($call_info);

    // Override devel's "nice trace" because ours is nicer.
    if ('ddebug_backtrace' === ($call_info['callee']['function'] ?? NULL) && $this->kintConfig->get('use_kint_trace_in_devel') && is_array($input)) {
      $dump = $this->dumpTraceFromDevel($kint_instance, $input);

      if (NULL !== $dump) {
        return $this->setSafeMarkup($dump);
      }
    }

    if ($name !== NULL) {
      $base = new BaseContext($name);
      $base->access_path = $name;
      $bases = [$base];
    }
    else {
      // This will need to be fixed upstream in kint-php/kint.
      // @phpstan-ignore argument.type
      $bases = Kint::getBasesFromParamInfo($call_info['params'] ?? [], 1);
    }

    $dump = $kint_instance->dumpAll([$input], $bases);

    return $this->setSafeMarkup($dump);
  }

  /**
   * Dumps a kint-style trace from the location ddebug_backtrace is set to use.
   *
   * @param \Kint\FacadeInterface $kint_instance
   *   The kint facade instance.
   * @param mixed[] $input
   *   The frames devel provided as the dump.
   */
  protected function dumpTraceFromDevel(FacadeInterface $kint_instance, array $input): ?string {
    // Devel adds a frame at the bottom for "main()"
    $expected_frames = \count($input) - 1;

    $options = $this->getDevelTraceOptions();

    if (NULL === $options) {
      return NULL;
    }

    $new_trace = \debug_backtrace($options);

    while (\count($new_trace) > $expected_frames) {
      \array_shift($new_trace);
    }

    if (!$new_trace) {
      return NULL;
    }

    $base = new BaseContext('ddebug_backtrace()');
    $base->access_path = 'debug_backtrace(' . \var_export($options, TRUE) . ')';
    $bases = [$base];

    return $kint_instance->dumpAll([$new_trace], $bases);
  }

  /**
   * Looks through the call stack and pulls out ddebug_backtrace's $options arg.
   */
  protected function getDevelTraceOptions(): ?int {
    $trace = \debug_backtrace();

    $callee = NULL;

    foreach ($trace as $frame) {
      if ('ddebug_backtrace' === $frame['function'] && !isset($frame['class'])) {
        $callee = $frame;
      }
    }

    if (NULL === $callee) {
      return NULL;
    }

    if (isset($callee['args'][2]) && \is_int($callee['args'][2])) {
      return $callee['args'][2];
    }

    return DEBUG_BACKTRACE_PROVIDE_OBJECT;
  }

}
