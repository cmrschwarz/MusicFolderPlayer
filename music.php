<?php
	$ini  = parse_ini_file(file_exists('music.ini') ? 'music.ini' : 'music.ini.template', true, INI_SCANNER_RAW);
	$cfg  = $ini['server'];
	$ext  = explode(',', $cfg['ext_songs'] .','. $cfg['ext_images']);
	$img  = explode(',', $cfg['ext_images']);

	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
	header('Cache-Control: post-check=0, pre-check=0', false);
	header('Pragma: no-cache');
	header('Content-Type: application/javascript; charset=utf-8');

	if (isset($_GET['dl']) && !in_array('..', explode('/', $_GET['dl']))) {
		$dl = urldecode(trim($_GET['dl'], '/'));
		if (is_dir($dl)) {
			if (!chdir($dl)) die('Could not open folder: '. $dl);
			return_zip($dl, ['.'], false);
		} elseif (file_exists($dl)) {
			header('Content-type: '. mime_content_type($dl));
			header('Content-Disposition: attachment; filename="'. basename($dl) .'"');
			readfile($dl);
			exit;
		} else die('File not found: '. $dl);
	} 
	elseif (isset($_GET['dlpl'])) {
		$plname = urldecode($_GET['dlpl']);
		$plfile = $cfg['playlistdir']. '/'. $plname. '.mfp.json';
		if (!file_exists($plfile)) die('Playlist not found: '. $plfile. PHP_EOL);
		$pl = (array)json_decode(json_decode(file_get_contents($plfile)));
		$files = array();
		foreach($pl['playlist'] as $song) { 
			$raw_path = ((array)$song)['path'];
			$path = $cfg['root']. '/'. $raw_path;
			if (!file_exists($path)) die('Playlist file not found: '. $path. PHP_EOL);
			array_push($files,  $path);
		}
		return_zip($plname, $files, true);
	}
	elseif (isset($_GET['pl'])) {
		$playlists = array();
		if (is_dir($cfg['playlistdir'])) {
			$scan = scandir($cfg['playlistdir']);
			foreach ($scan as $f)
				if (substr($f, -8) == 'mfp.json')
					$playlists[substr($f, 0, -9)] = json_decode(file_get_contents($cfg['playlistdir'] .'/'. $f));
		}
		die(json_encode($playlists));
	}

	$pl = json_decode(file_get_contents('php://input'), true);
	if (isset($pl['name'])) {
		if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
			if (chdir($cfg['playlistdir']))
				foreach (glob($pl['name'] .'.mfp.*') as $f)
					unlink($f);
			exit;
		}
		$name = $cfg['playlistdir'] .'/'. $pl['name'] .'.mfp.json';
		if (!is_dir($cfg['playlistdir'])) mkdir($cfg['playlistdir']);
		if (file_exists($name)) rename($name, $name .'.'. time());
		die(file_put_contents($name, json_encode($pl['songs'])));
	}

	foreach($ini['client'] as $key => $value)
		echo (stristr($key, '.') ? '' : 'var ') . $key .'='. $value .';'. PHP_EOL;
	
	$dir = $cfg['root'];
	if (isset($_GET['play']) && !in_array('..', explode('/', $_GET['play']))) {
		$dir = trim($_GET['play'], '/');
		if (!file_exists($dir))
			die('var root="'. $cfg['root'] .'/";'. PHP_EOL .'var library={"'. $cfg['notfound'] .'":""}');
		if (!is_dir($dir)) {
			$files = array();
			$files[$dir] = '';	// Add file
			$scan = scandir(dirname($dir));	// Scan parent folder for cover
			foreach ($scan as $f) {
				$ext = strtolower(substr($f, strrpos($f, '.') + 1));
				if (in_array($ext, $GLOBALS['img'])) {
					$files[$f] = '';
					break;
				}
			}
			echo 'var root="";'. PHP_EOL .'var library={"\/":'. json_encode($files) .'};';
			exit;
		}
	}

	echo 'var root="'. $dir .'/";'. PHP_EOL .'var library='. json_encode(tree($dir, 0));

	function tree($dir, $depth) {
		$scan = scandir($dir);
		$files = array();
		$tree = array();
		$hasmusic = false;

		foreach ($scan as $f) {
			if (substr($f, 0, 1) == '.')
				continue;
			if (is_dir("$dir/$f")) {
				if ($depth < $GLOBALS['cfg']['maxdepth']) {
					$subfolder = tree("$dir/$f", $depth + 1);
					if ($subfolder)
						$tree[$f] = $subfolder;
				}
			} else {
					$ext = strtolower(substr($f, strrpos($f, '.') + 1));
					if (in_array($ext, $GLOBALS['ext'])) {
						$files[$f] = '';
						if (!in_array($ext, $GLOBALS['img']))
							$hasmusic = true;
					}
			}
		}

		if ($hasmusic)
			$tree['/'] = $files;
		if (count((array) $tree) > 0)
			return $tree;
		else
			return false;
	}

	function return_zip($name, $paths, $flat){
		$windows = substr(php_uname(), 0, 7) == 'Windows';
		$temp_path = tempnam(sys_get_temp_dir(), "mfp_");
		$temp_file = fopen($temp_path, "w");
		if ($windows) {
			foreach($paths as $path) fwrite($temp_file, ($flat ? './' : ''). $path. "\r\n");
			$cmd = '7z a -tzip -mx1 -so -i@'. escapeshellarg($temp_path). basename($name);
		} else { 
			foreach($paths as $path) fwrite($temp_file, $path. "\n");
			$stdin = array('file', $temp_path, 'r');
			$cmd = 'zip - -0 '. ($flat ? '-j' : '-r'). ' -@ <'. escapeshellarg($temp_path);
		}
		fclose($temp_file);
		$descriptorspec = array(array('pipe', 'r'), array('pipe', 'w'), array('pipe', 'w'));
		$zip_proc = proc_open($cmd, $descriptorspec, $pipes);
		if (!$zip_proc) die('Error creating zip');
		header('Content-type: application/zip');
		header('Content-disposition: attachment; filename="'. basename($name) .'.zip"');
		$err = fread($pipes[2], 8192);
		fpassthru($pipes[1]);
		fclose($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[0]);
		$res = proc_close($zip_proc);
		if ($res != 0) {
			error_log('zip error (' . $res . '): '. $err. PHP_EOL);
			http_response_code(500);
		}
		unlink($temp_path);
		exit;
	}
?>
