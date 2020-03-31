<?php
/******************************************************
 A small library to interpret Hyperiontest.gr results
 since the National Telecommunications & Post Committee
 doesn't like comparing companies directly.

 Author : Stathis Oureilidis <stathis@stathis.ch>
 Date   : 01/04/2020
 License: MIT License
 *****************************************************/


require_once __DIR__ . '/vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;
use GeoIp2\Database\Reader;
use PhpSchool\CliMenu\CliMenu;
use PhpSchool\CliMenu\Builder\CliMenuBuilder;
use PhpSchool\CliMenu\Builder\SplitItemBuilder;


define( 'HYPERION_LINK', 'https://hyperiontest.gr/genCSV.php?d=o&s=2&f=_FROM_&t=_TO_' );
define( 'CWD', (function() {
	if (strpos(__DIR__, 'phar://') === 0) {
		return dirname(str_replace('phar://', '', __DIR__));
	}
	return __DIR__;
})());
define( 'CONF', Yaml::parseFile(CWD . '/config.yaml') );
define( 'CACHE', (function() {
	if (CONF['Cache'] == 'default') {
		return CWD . '/cache';
	}
	return CONF['Cache'];
})());

$menu = (new CliMenuBuilder)
	->setTitle('Hyperiontest.gr ISP comparison -- Stathis Oureilidis <stathis@stathis.ch>')
	->addLineBreak()
    ->addItem('Last 1 month',   function(CliMenu $menu) { getStats('1mo'); })
    ->addItem('Last 3 months',  function(CliMenu $menu) { getStats('3mo'); })
	->addItem('Last 12 months', function(CliMenu $menu) { getStats('12mo'); })
	->addItem('Previous year',  function(CliMenu $menu) { getStats('previousyear'); })
	->addItem('Custom range',   function(CliMenu $menu) {
		$result = $menu->askText()
			->setPromptText('Enter date range')
			->setPlaceholderText('Example: 15/02/2020-20/02/2020')
			->setValidator(function($timerange) {
				$exp = explode('-', $timerange);
				if (count($exp) !== 2) {
					return false;
				}
				$from = strtotime(str_replace('/', '-', $exp[0]));
				$to   = strtotime(str_replace('/', '-', $exp[1]));
				if ($from === false || $to === false || $from > $to)
				{
					return false;
				}
				return true;
			})
			->setValidationFailedText('Invalid format and/or time range. Please try again.')
			->ask();

		// Escape was pressed/we have placeholder text
		if (strpos($result->fetch(), 'Example: ') === 0) {
			return;
		}
		// Go
		getStats($result->fetch());
	})
	->addLineBreak()
	->addItem('Show cached CSVs', function(CliMenu $m) { $m->close(); showCached();  })
	->addLineBreak()
	->addLineBreak('-')
    ->setPadding(2, 4)
    ->setMarginAuto()
    ->build();
$menu->open();


function showCached() {
	$files = glob(CACHE . '/*-*.csv');
	$items = [];
	foreach ($files as $file) {
		$file_caption = (function() use ($file) {
			$date = explode('-', basename($file, '.csv'));
			$from = substr($date[0], 0, 2) . '/' . substr($date[0], 2, 2) . '/' . substr($date[0], 4);
			$to   = substr($date[1], 0, 2) . '/' . substr($date[1], 2, 2) . '/' . substr($date[1], 4);
			return $from . ' - ' . $to;
		})();
		$items[] = [$file_caption, function (CliMenu $m) use ($file) {
			$m->close();
			run(file($file));
		}];
	}

	$smenu = (new CliMenuBuilder)
		->setTitle('Cached data')
		->setPadding(2, 4)
		->setMarginAuto()
		->addLineBreak()
		->addItems($items)
		->addLineBreak()
		->addItem('Clear Cache', function(CliMenu $m) use ($files) {
			global $menu;
			$m->close();
			foreach ($files as $file) {
				unlink($file);
			}
			$menu->open();
		})
		->addLineBreak()
		->addLineBreak('-')
		->addItem('Back', function(CliMenu $m) { global $menu; $m->close(); $menu->open(); })
		->build();
	$smenu->open();
}

function getStats(string $timerange) {
	$from = null;
	$to   = null;

	switch ($timerange) {
		case '1mo':
			$from = date('r', strtotime('-1 month -1day'));
			$to   = date('r', strtotime('-1 day'));
			break;
		case '3mo':
			$from = date('r', strtotime('-3 months - 1day'));
			$to   = date('r', strtotime('-1 day'));
			break;
		case '12mo':
			$from = date('r', strtotime('-12 months - 1day'));
			$to   = date('r', strtotime('-1 day'));
			break;
		case 'previousyear':
			$from = '01 January ' . date('Y', strtotime('-1 year'));
			$to   = '31 December ' . date('Y', strtotime('-1 year'));
			break;
		default:
			$range = explode('-', $timerange);
			$from = date('r', strtotime(str_replace('/', '-', $range[0])));
			$to   = date('r', strtotime(str_replace('/', '-', $range[1])));
			break;
	}

	$arr = getCsv($from, $to);
	run($arr);
}

function getCsv($from, $to) {
	$fname = CACHE . '/' . date('dmY', strtotime($from)) . '-' . date('dmY', strtotime($to)) . '.csv';
	if (file_exists($fname)) {
		return file($fname);
	}
	$link_ = str_replace('_FROM_', date('d/m/Y', strtotime($from)), HYPERION_LINK);
	$link_ = str_replace('_TO_', date('d/m/Y', strtotime($to)), $link_);
	file_put_contents($fname, file_get_contents($link_));
	return file($fname);
}

function run($arr) {
	global $menu;
	$menu->close();

	$reader_asn = new Reader(CONF['GeoLite2ASN']);
	$csv = array_map(function($input) {
		return str_getcsv($input, ';');
	}, $arr);

	$result = [];
	for ($i = 1; $i < count($csv); $i++) {
		if (substr_count($csv[$i][0], '.') == 2) {
			$csv[$i][0] .= '.0';
		}
		if (\IPLib\Factory::addressFromString($csv[$i][0])->getRangeType() !== \IPLib\Range\Type::T_PUBLIC) {
			continue;
		}
		try {
			$asn = $reader_asn->asn($csv[$i][0]);
		} catch (GeoIp2\Exception\AddressNotFoundException $e) {
			// Address not in GeoIP DB, skip
			continue;
		}

		$isp = $asn->autonomousSystemOrganization;
		$asn = $asn->autonomousSystemNumber;
		if (!in_array($asn, CONF['Networks'])) {
			continue;
		}

		if (!isset($result[$asn])) {
			$result[$asn]['isp'] = $isp;
			$result[$asn]['count'] = 0;
			$result[$asn]['dl'] = 0;
			$result[$asn]['ul'] = 0;
			$result[$asn]['rttcount'] = 0;
			$result[$asn]['rtt'] = 0;
		}
		$result[$asn]['count']++;
		$result[$asn]['dl'] += $csv[$i][5];
		$result[$asn]['ul'] += $csv[$i][6];
		if ($csv[$i][7] > 0) {
			$result[$asn]['rttcount']++;
			$result[$asn]['rtt'] += $csv[$i][7];
		}
	}

	// Compute averages
	foreach ($result as $asn => $data) {
		$result[$asn]['dl']  = $data['dl'] / $data['count'];
		$result[$asn]['ul']  = $data['ul'] / $data['count'];
		$result[$asn]['rtt'] = $data['rtt'] / $data['rttcount'];
	}
	// Just a simple sort :)
	// Order: Higher DL, Higher UL, Lower RTT
	uasort($result, function($a, $b) {
		if ($a['dl'] == $b['dl']) {
			if ($a['ul'] == $b['ul']) {
				if ($a['rtt'] == $b['rtt']) {
					return 0;
				}
				return $a['rtt'] > $b['rtt'];
			}
			return $a['ul'] < $b['ul'];
		}
		return $a['dl'] < $b['dl'];
	});

	$smenu = (new CliMenuBuilder)
		->addLineBreak()
		->addLineBreak()
		->addSplitItem(function(SplitItemBuilder $b) {
			$b->setGutter(2)
				->addStaticItem('ASN - ISP')
				->addStaticItem('DL    / UL                 Latency');
		})
		->addLineBreak('*');

	foreach ($result as $asn => $data) {
		$avg_dl = sprintf("%.2f", round($data['dl'], 2));
		$avg_ul = sprintf("%.2f", round($data['ul'], 2));
		$avg_ms = sprintf("%.2f", round($data['rtt'], 2));
		$smenu = $smenu->addSplitItem(function(SplitItemBuilder $b) use ($asn, $avg_dl, $avg_ul, $avg_ms, $data) {
			$b->setGutter(2)
				->addStaticItem("AS{$asn} - {$data['isp']}")
				->addStaticItem("$avg_dl / $avg_ul [{$data['count']} pts]    {$avg_ms}ms [{$data['rttcount']} pts]");
		});
	}

	$exiting = false;

	$smenu = $smenu
		->setTitle('Results')
		->addLineBreak()
		->addLineBreak()
		->addLineBreak('-')
		->setWidth($smenu->getTerminal()->getWidth())
		->setMargin(2)
		->disableDefaultItems()
		->addItem('Back', function(CliMenu $m) {
			$m->close();
	   	})
		->addItem('Exit', function(CliMenu $m) use (&$exiting) {
			$exiting = true;
			$m->close();
		})
		->build();
	$smenu->open();

	if (!$exiting) {
		$menu->open();
	}
} // end mastori

