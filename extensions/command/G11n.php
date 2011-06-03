<?php
/**
 * Ali3: my stuff for lithium framework.
 *
 * @copyright     Copyright 2011, Ali Farhadi (https://github.com/farhadi/ali3)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace ali3\extensions\command;

use lithium\net\http\Service;
use lithium\g11n\Catalog;

/**
 * Same as lithium `G11n` commands with an additional translate command which uses
 * Google Translate API to translate messages.
 */
class G11n extends \lithium\console\command\G11n {

	/**
	 * Runs the `Translate` command.
	 *
	 * @return void
	 */
	public function translate() {
		$this->header('Message Translation');
		$configs = (array) Catalog::config();

		$this->out('Available `Catalog` Configurations:');
		foreach ($configs as $name => $config) {
			$this->out(" - {$name}");
		}
		$this->out();

		$name = $this->in('Please choose a configuration:', array(
			'choices' => array_keys($configs)
		));
		$this->out();
		$message[] = 'Translation is done using Google Translate API so a Google API key is needed';
		$message[] = 'to proceed. If you don\'t have a key visit the following URL to get one:';
		$message[] = 'https://code.google.com/apis/console/?api=translate';
		$this->out($message);
		$this->out();
		$key = $this->in('Google API Key:');
		$service = new Service(array(
			'scheme'     => 'https',
			'host'       => 'www.googleapis.com',
		));
		$result = $service->get('/language/translate/v2/languages', 'key=' . $key . '&target=en');
		$result = json_decode($result);
		if (!$result || $result->error) {
			$this->out($result->error ? 'Error: ' . $result->error->message : "An error occurred!");
			$this->stop(1);
		}
		$langs = array();
		$names = '';
		foreach ($result->data->languages as $i => $lang) {
			$langs[] = $lang->language;
			$names .= str_pad($lang->language . ': ' . $lang->name, 35) . ($i % 2 ? "\n" : '');
		}
		$this->out();
		$this->out('Available target languages:');
		$this->out($names);
		$this->out();
		$target = $this->in('Choose target language:', array('choices' => $langs));

		$data = Catalog::read($name, 'messageTemplate', 'root', array(
			'scope' => $configs[$name]['scope'],
			'lossy' => false,
		));

		$targetData = Catalog::read($name, 'message', $target, array(
			'scope' => $configs[$name]['scope'],
			'lossy' => false,
		));

		foreach (array_keys($targetData) as $id) {
			if (!isset($data[$id])) {
				unset($targetData[$id]);
			}
		}

		foreach ($data as $id => $item) {
			if (
				isset($targetData[$id]) &&
				!empty($targetData[$id]['translated']) &&
				$targetData[$id]['translated'] != $id
			) {
				continue;
			}
			$this->out();
			$this->out("Translating \"{$id}\" ...");
			$targetData[$id] = $item;
			$vars = array();
			$text = preg_replace_callback('/\{:.*?\}|\B\?\B/', function($matches) use (&$vars) {
				static $n = 0;
				$vars[] = $matches[0];
				return '_' . $n++ . '_';
			}, $id);
			$result = $service->get(
				'/language/translate/v2',
				'key=' . $key . '&source=en&target=' . $target . '&q=' . urlencode($text)
			);
			$result = json_decode($result);
			if (!$result || $result->error) {
				$targetData[$id]['translated'] = $id;
				$this->out("Failed Translating \"{$id}\".");
				continue;
			}
			$translated = $result->data->translations[0]->translatedText;
			if ($vars) {
				$translated = preg_replace_callback('/_(\d+)_/', function($matches) use (&$vars) {
					return $vars[$matches[1]];
				}, $translated);
			}
			$targetData[$id]['translated'] = $translated;
			$this->out("Translated Text: \"{$translated}\".");
		}

		$this->out();
		$message = array();
		$message[] = "\"$target\" catalog is now ready to be saved.";
		$message[] = "Please note that the existing \"$target\" catalog will be overwritten.";
		$this->out($message);
		$this->out();

		if ($this->in('Save?', array('choices' => array('y', 'n'), 'default' => 'y')) != 'y') {
			$this->out('Aborting upon user request.');
			$this->stop(1);
		}

		mkdir($configs[$name]['path'] . "/{$target}/LC_MESSAGES", 0755, true);
		$scope = $configs[$name]['scope'];
		Catalog::write($name, 'message', $target, $targetData, compact('scope'));
		unlink($configs[$name]['path'] . "/{$target}/LC_MESSAGES/" . ($scope ?: 'default') . '.mo');

		$this->out();
		return 0;
	}
}

?>