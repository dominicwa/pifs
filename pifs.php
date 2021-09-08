<?php
/*

PHP Image Framing Script (PIFS)
by Dominic Manley (http://dominicmanley.com/)
Version: 0.4 (07/09/21)

This script resamples images so they fit into a "frame" whilst maintaining
their aspect ratio. It accepts the following querystring parameters:

s - file path or http location of the image (if $allow_remote)
w - frame width
h - frame height
r - resize width/height only if smaller/bigger ('ws','hs','wb','hb','wshs','wbhs', ...)
f - fill the frame? (1 or 0)
c - frame background colour (a *six* character hex value)
i - ignore/replace cache version (1 or 0)
e - password for emptying cache

*/

error_reporting(E_ALL ^ E_NOTICE);

// Configure the script.

$allow_remote = array();			// allowed remote http(s) image locations
$cache_save = true;					// highly recommended to speed things up
$cache_path = 'cache/';				// needs to be writeable by the web server user
$empty_cache_pw = '';				// password for emptying cache using e parameter
$jpg_quality = 100;					// 0-100, higher the better
$png_quality = 0;					// 0-9, lower the better (php 5.1.2+ only)

// Empty cache if correct password provided.

if ($empty_cache_pw != '' && $_GET['e'] == $empty_cache_pw) {
	$cache_dir = scandir($cache_path);
	foreach ($cache_dir as $f) {
		if (in_array(substr($f, -3), array('jpg','png','gif'))) {
			unlink($cache_path . DIRECTORY_SEPARATOR . $f);
		}
	}
}

// Exit immediately if no image source provided.

if ($_GET['s'] == '') exit('Error: no source image provided.');

// Exit immediately if image source is remote and not listed in $allow_remote.

if (sizeof($allow_remote) == 0 && stripos($_GET['s'], 'http') !== false)
	exit('Error: remote images not allowed.');

if (sizeof($allow_remote) > 0) {
	$bAllowed = false;
	foreach ($allow_remote as $l)
		if (stripos($_GET['s'], $l) === 0)
			$bAllowed = true;
	if (!$bAllowed)
		exit('Error: remote image from that location not allowed.');
}

// Get the file extension of the source image.

$img_ext = strtolower(substr(basename($_GET['s']), -3));

// Build the filename.

$img_fn  = str_replace(array('.', '&', '?', '/', ':'), '_', $_GET['s']);
$img_fn .= '_' . $_GET['w'] . '_' . $_GET['h'] . '_' . $_GET['f'];
$img_fn .= '_' . $_GET['r'] . '_' . $_GET['c'] . '.' . $img_ext;

// Check for existance in cache and serve instead.

if (file_exists($cache_path . $img_fn) && $_GET['i'] != 1) {
	if ($img_ext == 'jpg') {
		 header('Content-type: image/jpeg');
	}
	
	if ($img_ext == 'png' || $img_ext == 'gif') {
		 header('Content-type: image/png');
	}
	
	header('Content-Disposition: attachment; filename="' . $img_fn . '"');
	header('Cache-Control: max-age=10000000, s-maxage=1000000, proxy-revalidate, must-revalidate');
	
	echo file_get_contents($cache_path . $img_fn);
	exit();
}

// Create an image resource based on the file extension of the source image.
// This is a primative way of determining the source image type for now. In
// the future it might be better to check its mime type.

if ($img_ext == 'jpg') {
	$img_src = @imagecreatefromjpeg($_GET['s']);
}

if ($img_ext == 'png') {
	$img_src = @imagecreatefrompng($_GET['s']);
}

if ($img_ext == 'gif') {
	$img_src = @imagecreatefromgif($_GET['s']);
}

// If we failed to create a source image resource, build our own 100 x 100
// error image so at least something gets sent back.

if (!$img_src || @fopen($_GET['s'], "r") == false) {
	// This bit *must* work or no image is sent back.
	$img_src = imagecreate (100, 100);
	$bgc = imagecolorallocate ($img_src, 255, 255, 255);
	$fgc = imagecolorallocate ($img_src, 0, 0, 0);
	imagefilledrectangle ($img_src, 0, 0, 100, 100, $bgc);
	imagestring ($img_src, 2, 6, 35, "Sorry, no image", $fgc);
	imagestring ($img_src, 2, 20, 48, "available.", $fgc);
}

// Determine the dimensions of our source image and set the destination
// dimensions to the same by default.

$src_wid = imagesx($img_src);
$src_hei = imagesy($img_src);

$des_wid = $src_wid;
$des_hei = $src_hei;

// Reset the destination dimensions using any querystring parameters.
// Make sure the destination is larger than 10 pixels in both axis.
// Anything lower is silly and might(?) cause errors further down.

if (is_numeric($_GET['w']) && $_GET['w'] > 10) {
	if ($_GET['r'] == '' ||
		(strstr($_GET['r'], 'ws') != false && $des_wid > intval($_GET['w'])) ||
		(strstr($_GET['r'], 'wb') != false && $des_wid < intval($_GET['w'])) ) {
		$des_wid = $_GET['w'];
	}
}

if (is_numeric($_GET['h']) && $_GET['h'] > 10) {
	if ($_GET['r'] == '' ||
		(strstr($_GET['r'], 'hs') != false && $des_hei > intval($_GET['h'])) ||
		(strstr($_GET['r'], 'hb') != false && $des_hei < intval($_GET['h'])) ) {
		$des_hei = $_GET['h'];
	}
}

// Create our destination image resource (the frame).

if ($img_ext == 'jpg' ||
	$img_ext == 'png' ||
	$img_ext == 'gif') {
	$img_des = imagecreatetruecolor($des_wid, $des_hei);
}

// Set the background colour of our destination image resource.
// This will result in black if no querystring parameter 'c' is sent through.

$r = hexdec(substr($_GET['c'], 0, 2));
$g = hexdec(substr($_GET['c'], 2, 2));
$b = hexdec(substr($_GET['c'], 4, 2));

$bgc = imagecolorallocate ($img_des, $r, $g, $b);
imagefilledrectangle ($img_des, 0, 0, $des_wid, $des_hei, $bgc);

// Declare a heap of variables we'll be using for calculating the size of the
// destination image.

$src_xpos = 0;
$src_ypos = 0;
$res_size = 1;
$res_sizx = $des_wid / $src_wid;
$res_sizy = $des_hei / $src_hei;
$res_xpos = 0;
$res_ypos = 0;
$res_xwid = $des_wid;
$res_yhei = $des_hei;

// Perform our calculations (too complex to describe here).

if ($des_wid >= $des_hei) { // destination is landscape

	if ($res_sizx >= $res_sizy) {
	
		if ($_GET['f'] == 1) {
			$res_size = $des_wid / $src_wid;
			$src_ypos = ($src_hei - ($des_hei / $res_size)) / 2;
			$src_hei  = $des_hei / $res_size;
		} else {
			$res_size = $des_hei / $src_hei;
			$res_xwid = $src_wid * $res_size;
			$res_yhei = $src_hei * $res_size;
			$res_xpos = ($des_wid - $res_xwid) / 2;
		}

	} else {

		if ($_GET['f'] == 1) {
			$res_size = $des_hei / $src_hei;
			$src_xpos = ($src_wid - ($des_wid / $res_size)) / 2;
			$src_wid  = $des_wid / $res_size;
		} else {
			$res_size = $des_wid / $src_wid;
			$res_xwid = $src_wid * $res_size;
			$res_yhei = $src_hei * $res_size;
			$res_ypos = ($des_hei - $res_yhei) / 2;
		}

	}

} else { // destination is portrait

	if ($res_sizx >= $res_sizy) {

		if ($_GET['f'] == 1) {
			$res_size = $des_wid / $src_wid;
			$src_ypos = ($src_hei - ($des_hei / $res_size)) / 2;
			$src_hei  = $des_hei / $res_size;
		} else {
			$res_size = $des_hei / $src_hei;
			$res_xwid = $src_wid * $res_size;
			$res_yhei = $src_hei * $res_size;
			$res_xpos = ($des_wid - $res_xwid) / 2;
		}

	} else {

		if ($_GET['f'] == 1) {
			$res_size = $des_hei / $src_hei;
			$src_xpos = ($src_wid - ($des_wid / $res_size)) / 2;
			$src_wid  = $des_wid / $res_size;
		} else {
			$res_size = $des_wid / $src_wid;
			$res_xwid = $src_wid * $res_size;
			$res_yhei = $src_hei * $res_size;
			$res_ypos = ($des_hei - $res_yhei) / 2;
		}

	}

}

// Blits our source image, resampled, onto the destination image frame.

imagecopyresampled($img_des, $img_src, $res_xpos, $res_ypos, $src_xpos,
	$src_ypos, $res_xwid, $res_yhei, $src_wid, $src_hei);

// Send the appropriate content type header based on the file extension of the
// source image.

if ($img_ext == 'jpg') {
	 header('Content-type: image/jpeg');
}

if ($img_ext == 'png' || $img_ext == 'gif') {
	 header('Content-type: image/png');
}

// Send out filename and cache control header information.

if (!$_GET['debug']) {
	header('Content-Disposition: attachment; filename="' . $img_fn . '"');
}
header('Cache-Control: max-age=10000000, s-maxage=1000000, proxy-revalidate, must-revalidate');
header('Last-Modified: ' . gmdate("D, d M Y H:i:s",mktime (0,0,0,1,1,2000)) . ' GMT');
header('Expires: Mon, 26 Jul 2040 05:00:00 GMT');

// Send out the image data of our destination image resource.
// If this image has never been framed like this before, store a copy in the
// cache (if enabled).

if ($img_ext == 'jpg') {
	if ($cache_save == true) {
		if (!file_exists($cache_path . $img_fn) || $_GET['i'] == 1) {
			imagejpeg($img_des, $cache_path . $img_fn, $jpg_quality);
		}
	}
	imagejpeg($img_des, NULL, $jpg_quality);
}

if ($img_ext == 'png' || $img_ext == 'gif') {
	if ($cache_save == true) {
		if (!file_exists($cache_path . $img_fn) || $_GET['i'] == 1) {
			imagepng($img_des, $cache_path . $img_fn, $png_quality);
		}
	}
	imagepng($img_des, NULL, $png_quality);
}

// Clearup

imagedestroy($img_des);
imagedestroy($img_src);

?>    