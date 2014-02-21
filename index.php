<?php

$CSV_STRUCTURE = array(
	'postcode','positional_quality_indicator','eastings',
	'northings','country_code','nhs_regional_ha_code',
	'nhs_ha_code','admin_county_code','admin_district_code',
	'admin_ward_code'
);

require_once('phpcoord/phpcoord-2.3.php');

$f3=require('lib/base.php');

$f3->config('config.ini');

$url = $f3->get("SCHEME")."://".$f3->get("HOST").$f3->get("BASE");
$f3->set("BASEURL", $url);

$f3->run();

function postcode_to_data($f3,$postcode)
{
	$output = array();

	if (!valid_postcode($postcode))
	{
		$output['error'] = "Submitted value doesn't look anything like a postcode!";
		echo json_encode($output);
		exit;
	}

	$data_file = postcode_to_filepath($f3, $postcode);
	if (!$data_file || !file_exists($data_file))
	{
		$output['error'] = "Couldn't find file for $postcode";
		echo json_encode($output);
		exit;
	}

	$data = postcode_line_from_file($f3, $data_file, $postcode);
	if (!$data)
	{
		$output['error'] = "Couldn't get data for $postcode";
		echo json_encode($output);
		exit;
	}


	return $data;
}

function postcode_batch_distance($f3, $params)
{
	$output = array();

	$primary_data = postcode_to_data($f3,$params['postcode']);
	$output['primary_postcode'] = $primary_data['postcode'];
	$output['bad_postcodes'] = array();
	$output['distances'] = array();

	$postcode_param = $f3->get('REQUEST.postcodelist');

	$postcodes = explode(',',$postcode_param);

	foreach ($postcodes as $postcode)
	{
		if (!valid_postcode($postcode))
		{
			$output['bad_postcodes'][] = $postcode;
			continue;
		}
		$data_file = postcode_to_filepath($f3, $postcode);

		if (!$data_file || !file_exists($data_file))
		{
			$output['bad_postcodes'][] = $postcode;
			continue;
		}

		$data = postcode_line_from_file($f3, $data_file, $postcode);
		if (!$data)
		{
			$output['bad_postcodes'][] = $postcode;
			continue;
		}
		$distance = calculate_distance($primary_data, $data);
		$output['distances'][$data['postcode']] = $distance;
	}

	output_data($output);
}

function postcode_distance($f3, $params)
{
	$data1 = postcode_to_data($f3,$params['postcode1']);
	$data2 = postcode_to_data($f3,$params['postcode2']);

	output_data(calculate_distance($data1,$data2));
}

function calculate_distance($data1,$data2)
{
	$e_delta = $data1['eastings'] - $data2['eastings'];
	$n_delta = $data1['northings'] - $data2['northings'];
	#pythagorus
	$distance_metres = round(sqrt( pow($e_delta,2) + pow($n_delta,2) ));
	$distance_miles = round( ($distance_metres / 1609.344), 2);

	$distance = array(
		'postcode1' => $data1['postcode'],
		'postcode2' => $data2['postcode'],
		'distance_metres' => $distance_metres,
		'distance_miles' => $distance_miles,
	);
	return $distance;
}

function UK_easting_and_northing($f3, $params)
{
	$data = postcode_to_data($f3,$params['postcode']);

	$output = array();
	$output['eastings'] = $data['eastings'];
	$output['northings'] = $data['northings'];

	output_data($output);
}

function output_data($data)
{
	$data['attribution'] = "Contains Ordnance Survey data © Crown copyright and database right. Contains Royal Mail data © Royal Mail copyright and database right. Contains National Statistics data © Crown copyright and database right.";

	echo json_encode($data);
}


function all_data($f3, $params)
{
	$data = postcode_to_data($f3,$params['postcode']);

	add_lat_long($data);	
	add_google_map_link($data);

	output_data($data);
}


function add_google_map_link(&$data)
{
	$data['google_map_link'] = "https://www.google.co.uk/maps/place/" . $data['postcode'];
}

function add_lat_long(&$data)
{
	$os = new OSRef($data['eastings'], $data['northings']);
	$ll = $os->toLatLng();

	$lng = $ll->lng;
	$lat = $ll->lat;

	$data['latitude'] = $lat;
	$data['longitude'] = $lng;
	$data['longlat'] = "$lat,$lng";
	#these coordinates appear to be off
}

function normalise_postcode($postcode)
{
	#lower case
	$postcode = strtoupper($postcode);
	#remove spaces
	$postcode = preg_replace('/\s+/', '', $postcode);
	return $postcode;
}

#takes a filepath and returns the line that begins with the postcode
function postcode_line_from_file($f3,$filepath,$postcode)
{
	$postcode = normalise_postcode($postcode);

	$line = null;

	if (($handle = fopen("$filepath", "r")) !== FALSE) {
		while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
			if ($data[0] == $postcode)
			{
				$line = $data;
			}
		}
		fclose($handle);
	}

	if (!$line)
	{
		return null;
	}

	global $CSV_STRUCTURE;
	return array_combine($CSV_STRUCTURE, $line);
}


function postcode_to_filepath($f3, $postcode)
{
	$postcode = normalise_postcode($postcode);

	$first_letters = null;
	if (preg_match('/^[iA-Z]+/', $postcode, $matches))
	{
		$first_letters = strtolower($matches[0]);
	}
	else
	{
		return null;
	}

	$filename = $f3->get('POSTCODE_CSV_ROOT') . $first_letters . '.csv';

	return $filename;
}


#basic checks
function valid_postcode($postcode)
{
	$postcode = normalise_postcode($postcode);

	#a postcode-shaped string is between 4 and 10 alphanumic chars
	$opts = array( "options" => array( "regexp" => '/^[0-9A-Z]{4,10}$/' ));
	if (filter_var($postcode, FILTER_VALIDATE_REGEXP, $opts ) === false)
	{
		return false;
	}
	return true;
}

