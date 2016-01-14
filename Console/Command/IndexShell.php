<?php

use Elasticsearch\Client;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Elasticsearch\ClientBuilder;
use Aws\ElasticsearchService\ElasticsearchServiceClient;
use Elasticsearch\Common\Exceptions\Forbidden403Exception;
// Can be called by "Console/cake index"

App::uses('Shell', 'Console');

App::import('Controller', 'Aperture');

class IndexShell extends AppShell {
	
	const INDEX = 'aperture';
	const ZIP = 15;
	const CITY = 16;
	const COUNTRY = 1;

	private $properties = [
			//'Byline',
			'copyright_notice' => 'CopyrightNotice',
			'email' => 'CiEmailWork',
			'usage_conditon' => 'UsageTerms',
			//'City',
			//'SubLocation',
			'label' => 'ObjectName',
			'master_location' => 'MasterLocation',
			'aspect_ratio' => 'AspectRatio',
			'pixel_size' => 'PixelSize',
			'project_name' => 'ProjectName',
			'lens_model' => 'LensModel',
			'model' => 'Model',
			'copyright' => 'Copyright',
			'artist' => 'Artist',
			'user_comment' => 'UserComment',
			//'Altitude',
			//'Longitude',
			//'Latitude'
	];

	private $client;
	private $s3;
	private $keywords;
	private $googleApiKey;
	private $places;
	private $locations;
	private $zipCodes;

	private function buildFindversionOptions($page){

		return array(
				// 				'limit' => 20,
				'conditions' => array(
						'Version.isFlagged' => 1,
						'Version.showInLibrary' => 1,
						'Version.isHidden' => 0,
						'Version.isInTrash' => 0,
						'Version.mainRating >=' => 0,
// 						'Version.uuid' => 'XccAqOzDQSmT7dEWaXK7Dw'
				),
				'contain' => array(
						'PlaceForVersion' => array(
								'fields' => array('placeId')
						),
						'Keyword' => array(
								'fields' =>  array('name', 'modelId', 'encodedUuid'),
								/*'conditions' => [
										'placeId' => array_keys($this->keywords)
									]/**/
						)),
				'order' => array('Version.imageDate DESC'),
				'limit' => 100,
				'page' => $page,
				//'fields' => array('Version.imageDate', 'Version.encodedUuid', 'Version.name', 'Version.exifLatitude', 'Version.exifLongitude', 'Version.unixImageDate', 'Version.stackUuid'),
				//'group' => array( 'Version.imageDate', 'Version.encodedUuid', 'Version.name', 'Version.exifLatitude', 'Version.exifLongitude', 'Version.unixImageDate', 'Version.stackUuid'),
		);

	}

	public function initialize()
	{
		parent::initialize();
		//$this->loadModel('Gallery');
		$this->loadModel('Locality');
		$this->loadModel('ApertureConnector.Keyword');
		$this->loadModel('ApertureConnector.Place');
		$this->loadModel('ApertureConnector.Version');
		$this->loadModel('ApertureConnector.IptcProperty');
		$this->loadModel('ApertureConnector.OtherProperty');
		$this->loadModel('ApertureConnector.ExifStringProperty');
		$this->loadModel('ApertureConnector.ExifNumberProperty');
		$this->loadModel('ApertureConnector.ImageProxyState');
	}

	private function putToS3($uuid, $encodedUuid){

// 		return true;
		$imageProxy = $this->ImageProxyState->findByVersionuuid($uuid);

		$filePath = APP."../Thumbnails".DS.$imageProxy['ImageProxyState']['thumbnailPath'];

		if($imageProxy && file_exists($filePath)){

			try {
				$result = $this->s3->headObject([
						'Bucket' => 'global-previews',
						'Key'    => $encodedUuid
				]);
			} catch (S3Exception $e) {
				//var_dump($e); exit;
// 				$this->out($e->getMessage());
// 				$this->out("Access denied to the S3 bucket 113");
				$result = false;
			}

			if(!$result || $result->get('ContentLength') != filesize( $filePath )){
				echo "Put $encodedUuid\n";
				$this->s3->putObject([
				    'Bucket' => 'global-previews',
				    'Key'    => $encodedUuid,
				    'SourceFile'   => $filePath
				]);/**/
			}
			return true;
		}
		else {
			try {
				$result = $this->s3->headObject([
						'Bucket' => 'global-previews',
						'Key'    => $encodedUuid
				]);
				$this->out("file not found for uuid $uuid: $filePath, we asume that the file is up to date");
				return true;
			} catch (S3Exception $e) {
			}
		}
		$this->out("file not found for uuid $uuid: $filePath and also missing on S3");
		return false;

	}

	private function getLocality($lat, $lng, $language){
		$url = "http://nominatim.openstreetmap.org/reverse?format=json&lat=$lat&lon=$lng&zoom=18&addressdetails=1&accept-language=$language";
		// get the json response
		$resp_json = file_get_contents($url);
		
		var_dump(json_decode($resp_json));
		exit;
		
		
	}
	
	private function getLocalityGoogle($lat, $lng, $language){

		$out = [
			//'model_id' => $modelId,
			'language' => $language
		];

		// google map geocode api url
		$url = "https://maps.googleapis.com/maps/api/geocode/json?latlng=$lat,$lng&sensor=false&language=$language&key=".$this->googleApiKey;

		
		//echo $url;
		// get the json response
		$resp_json = file_get_contents($url);

		// decode the json
		$resp = json_decode($resp_json, true);

		// response status will be 'OK', if able to geocode given address
		if($resp['status']=='OK'){
			/*$out['place_id'] = $resp['results'][0]['place_id'];

			foreach ($resp['results'] as $result) {
				$component = $result['address_components'][0];
				if(in_array('locality', $component['types'])){
					$out['locality'] = $component['long_name'];
				}
				else if(in_array('administrative_area_level_2', $component['types'])){
					$out['admin_level_2'] = $component['long_name'];
					$out['admin_level_2_code'] = $component['short_name'];
				}
				else if(in_array('administrative_area_level_1', $component['types'])){
					$out['admin_level_1'] = $component['long_name'];
					$out['admin_level_1_code'] = $component['short_name'];
				}
				else if(in_array('country', $component['types'])){
					$out['country'] = $component['long_name'];
					$out['country_code'] = $component['short_name'];
				}
				else if(in_array('postal_code', $component['types'])){
					$out['postal_code'] = $component['long_name'];
				}
			}
			return ['Locality' => $out];
			*/
			return $resp['results'];

		}else{
				var_dump($resp_json);
				return false;
		}
	}

	
	private function getAnotherImageInTheSameLocality($countryUuid, $zipCode) {
		$data = '
				{
				   "size": 1,
				   "query": {
				      "bool": {
				         "must": [
				            {
				               "nested": {
				                  "path": "locations",
				                  "query": {
				                     "term": {
				                        "locations.uuid": {
				                           "value": '.json_encode($countryUuid).'
				                        }
				                     }
				                  }
				               }
				            },
				            {
				               "nested": {
				                  "path": "locations",
				                  "query": {
				                     "term": {
				                        "locations.name": {
				                           "value": '.json_encode($zipCode).'
				                        }
				                     }
				                  }
				               }
				            }
				         ]
				      }
				   }
				}
				
				';
		
// 		$this->out($data);
		
		$params = [
			'index' => self::INDEX,
			'type' => 'version',
			'body' => $data
		];
		$ret = $this->client->search($params);
// 		var_dump($ret);
		return ($ret["hits"]["total"]>0)?$ret["hits"]["hits"][0]["_source"]:false;
	}
	
	
	private function getZipCode($lat, $lng, &$locations) {
		if( ! isset($this->zipCodes) ) {
			$this->zipCodes = [];
		}
		
		if(isset($locations[self::CITY]) && isset($this->zipCodes[$locations[self::CITY]['modelId']])){
			$locations[self::ZIP] = $this->zipCodes[$locations[self::CITY]['modelId']];
			return;
		}
		
		
		$localities = $this->getLocalityGoogle($lat, $lng, "en");
		
		
		foreach ($localities as $locality) {
			if( strcmp($locality['types'][0], 'postal_code') == 0 ) {
				$temp = [];
				
				if(!isset($locations[self::CITY])){
					
					$version = $this->getAnotherImageInTheSameLocality($locations[self::COUNTRY]['uuid'], $locality['address_components'][0]['long_name']);
					if($version) {
// 						var_dump($version);
						foreach ($version['locations'] as $local){
							if($local['level'] == self::CITY){
// 								$this->out("city found");
								$locations[self::CITY] = $local;
							}
							if($local['level'] == self::ZIP){
								$locations[self::ZIP] = $local;
// 								$this->out("zip found");
							}
						}
					}
				}
				else{
					$temp['level'] = self::ZIP;
					$temp['southwest'] = $locations[self::CITY]['southwest'];
					$temp['northeast'] = $locations[self::CITY]['northeast'];
					$temp['location'] = $locations[self::CITY]['location'];
					$temp['uuid'] = $locations[self::CITY]['uuid'];
					$temp['name'] = $locality['address_components'][0]['long_name'];
					foreach ($this->languages as $language){
						$temp['name_'.$language] = $temp['name'];
					}
					$locations[self::ZIP] = $temp;
					$this->zipCodes[$locations[self::CITY]['modelId']] = $temp;					
				}				
				
				break;
			}
		}
	}
	
	private function addGoogleLocationsInformations($lat, $lng, &$locations) {
		foreach ($this->languages as $language){
			$localities = $this->getLocalityGoogle($lat, $lng, $language);
			
//  			var_dump($localities);
// 			exit;
			if($localities){
				foreach ($localities as $locality) {
					$type = false;
					switch ($locality['types'][0]) {
						case 'intersection':
							$type = 140;
							break;
						case 'route':
							$type = 138;
							break;
						case 'park':
							$type = 139;
							break;
						case 'premise':
							$type = 137;
							break;
						case 'neighborhood':
							$type = 136;
							break;
						case 'airport':
							$type = 84;
							break;
						case 'train_station':
							$type = 83;
							break;
						case 'subway_station':
							$type = 82;
							break;
						case 'bus_station':
							$type = 81;
							break;
						case 'transit_station':
							$type = 80;
							break;
						case 'sublocality_level_1':
							$type = 60;
							break;
						case 'locality':
							$type = 48;
							break;
						case 'postal_code':
							$type = 45;
							break;
						case 'administrative_area_level_5':
							$type = 18;
							break;
						case 'administrative_area_level_4':
							$type = 15;
							break;
						case 'administrative_area_level_3':
							$type = 8;
							break;
						case 'administrative_area_level_2':
							$type = 6;
							break;
						case 'administrative_area_level_1':
							$type = 4;
							break;
						case 'country':
							$type = 3;
							break;
						case 'street_address':
							break;
						default:
							echo "Unknowed locality level: ".$locality['types'][0]."\n";
							//var_dump($locality);
							break;
					}

					if($type){
						if(!isset($locations[$type])){
								$locations[$type]  = array(
									'level' => $type,
									'type' => $locality['types'][0],
									'name' => $locality['address_components'][0]['long_name'],
								);
						}
						$locations[$type]['modelId'] = $locality['place_id'];
						$locations[$type]['model'] = 'google';
						$locations[$type]['name_'.$language] = $locality['address_components'][0]['long_name'];
						
						$locations[$type]['geometry'] = [];
						
						$locations[$type]['geometry']['location'] = [
												'lat' => $locality['geometry']['location']['lat'],
												'lon' => $locality['geometry']['location']['lng'],
										];
						
						if(isset($locality['geometry']['bounds']) 
								&& $locality['geometry']['bounds']['northeast']['lat'] && $locality['geometry']['bounds']['northeast']['lng']
								&& $locality['geometry']['bounds']['southwest']['lat'] && $locality['geometry']['bounds']['southwest']['lng']){
							$locations[$type]['geometry']['bounds'] = [
											'northeast' => [
													'lat' => $locality['geometry']['bounds']['northeast']['lat'],
													'lon' => $locality['geometry']['bounds']['northeast']['lng'],
											],
											'southwest' => [
													'lat' => $locality['geometry']['bounds']['southwest']['lat'],
													'lon' => $locality['geometry']['bounds']['southwest']['lng'],
											],
									];							
						}
						
						
						$this->updateLocation($locations[$type]);
					}

				}
			}

		}
	}

	private function updateLocation($locality){
// 		if(!isset($this->locations[$locality['modelId']])){
			$this->places[$locality['modelId']] = true;
			$this->putObjectToElasticSearch($locality, "place", $locality['modelId']);
// 		}
	}

	private function addCityInformations($modelId, $coordinates, &$data){
		list($head, $lng, $lat, $queue) = preg_split("/[\{,\s\}]+/", $coordinates);
		//list($head, $lng, $lat, $queue) = split('[]', $date);
		//var_dump($coordinates);


		foreach ($this->languages as $language){
			$locality = $this->Locality->findByModelIdAndLanguage($modelId, $language);

			if(!$locality){
				//$localityx
				$locality = $this->getLocality($lat, $lng, $language);
				if($locality){
					$this->Locality->save($locality);
				}
			}

			if($locality){
				$data['locality_'.$language] = $locality['Locality']['locality'];
				$data['locality_place_id'] = $locality['Locality']['place_id'];
				$data['country_'.$language] = $locality['Locality']['country'];
				$data['postal_code'] = $locality['Locality']['postal_code'];
				$data['admin_level_2_'.$language] = $locality['Locality']['admin_level_2'];
				$data['admin_level_1_'.$language] = $locality['Locality']['admin_level_1'];
			}


			$this->Locality->clear();

		}

		//var_dump($locality);

		//exit;
	}

	public function updateVersionsInfo($page){
		$this->out("Indexing versions page $page...");
// 		$aperture = new ApertureConnectorAppController()rtureController();
// 		$aperture->constructClasses(); //I needed this in here for more complicated requiring component loads etc in the Controller

// 		$findversionOptions = $aperture->buildFindversionOptions();

		$findversionOptions = $this->buildFindversionOptions($page);
		
		$placesFound = array();


		$versions = $this->Version->find('all', $findversionOptions);
		foreach ($versions as $version){
			$data = [];
			$this->out($version['Version']['name'].': '.$version['Version']['modelId']);
			$data['uuid'] = $version['Version']['encodedUuid'];
			$data['name'] = $version['Version']['name'];
			$data['width'] = $version['Version']['masterWidth'];
			$data['height'] = $version['Version']['masterHeight'];
			$data['rating'] = $version['Version']['mainRating'];
			$data['date'] = date('Y/m/d H:i:s', $version['Version']['unixImageDate']);
			$data['lastUpdateDate'] = date('Y/m/d H:i:s');
			//yyyy-MM-dd’T'HH:mm:ss.SSSZZ
			
			if(!is_null($version['Version']['exifLatitude']) && !is_null($version['Version']['exifLongitude'])){
				$data['location'] = array(
						'lat' => $version['Version']['exifLatitude'],
						'lon' => $version['Version']['exifLongitude']
				);
			}
			else {

				$this->err("Location missing");
			}
			$data['repository_path'] = APP;



			//$data['Properties'] = array();

			$this->IptcProperty->contain('UniqueString');
			$props = $this->IptcProperty->findAllByVersionid($version['Version']['modelId']);
			foreach($props as $prop){
				if(in_array($prop['IptcProperty']['propertyKey'], $this->properties))
					$data[array_search($prop['IptcProperty']['propertyKey'], $this->properties)] = $prop['UniqueString']['stringProperty'];
			}

			$this->OtherProperty->contain('UniqueString');
			$props = $this->OtherProperty->findAllByVersionid($version['Version']['modelId']);
			foreach($props as $prop){
				if(in_array($prop['OtherProperty']['propertyKey'], $this->properties))
					$data[array_search($prop['OtherProperty']['propertyKey'], $this->properties)] = $prop['UniqueString']['stringProperty'];
			}

			$this->ExifStringProperty->contain('UniqueString');
			$props = $this->ExifStringProperty->findAllByVersionid($version['Version']['modelId']);
			foreach($props as $prop){
				if(in_array($prop['ExifStringProperty']['propertyKey'], $this->properties))
					$data[array_search($prop['ExifStringProperty']['propertyKey'], $this->properties)] = $prop['UniqueString']['stringProperty'];
			}

			$props = $this->ExifNumberProperty->findAllByVersionid($version['Version']['modelId']);
			foreach($props as $prop){
				if(in_array($prop['ExifNumberProperty']['propertyKey'], $this->properties))
					$data[array_search($prop['ExifNumberProperty']['propertyKey'], $this->properties)] = $prop['ExifNumberProperty']['numberProperty'];
			}

			$locations = array();

			foreach( $version['PlaceForVersion'] as $location ){

				$locationNames = $this->Place->find('all', [

					'conditions' => [
							'modelId' => $location['placeId'],
							//'type >= ' => 16
					],
					'contain' => array(
						'PlaceName' /*=> array(
								'fields' => array('language', 'description'),
						)*/),
				]);


				foreach ($locationNames as $locationName){
					/*if($locationName['Place']['type'] == 16){
							$this->addCityInformations($locationName['Place']['modelId'], $locationName['Place']['centroid'], $data);
					}*/
					

					$temp = array(
						'level' => $locationName['Place']['type'],
						'modelId' => $locationName['Place']['modelId'],
						'uuid' => $locationName['Place']['encodedUuid'],
// 						'model' => 'aperture',
						'name' => $locationName['Place']['defaultName'],
					);
					
					
					$splited = preg_split("/[\{,\s\}]+/", $locationName['Place']['centroid']);
					
					if(count($splited) == 4){
						$lng = $splited[1];
						$lat = $splited[2];
					}
					else {
						$lng = $splited[0];
						$lat = $splited[1];
					}
					
					

					$temp['northeast'] = [
						'lat' => $locationName['Place']['maxLatitude'],
						'lon' => $locationName['Place']['maxLongitude'],
					];
					$temp['southwest'] = [
						'lat' => $locationName['Place']['minLatitude'],
						'lon' => $locationName['Place']['minLongitude'],
					];
					$temp['location'] = [
						'lat' => $lat,
						'lon' => $lng,
					];
						

					switch ($locationName['Place']['type']) {
						case 1:
							$temp['type'] = 'country';
							break;
						case 2:
							$temp['type'] = 'district';
							break;
						case 4:
							$temp['type'] = 'department';
							break;
						case self::CITY:
							$temp['type'] = 'city';
							break;
						case 43:
							$temp['type'] = 'town';
							break;
						case 44:
							$temp['type'] = 'sea';
							break;
						case 45:
							$temp['type'] = 'neighborhood_1';
							break;
						case 47:
							$temp['type'] = 'rooftop';
							break;
						default:
							# code...
							break;
					}

					//echo $locationName['Place']['defaultName']."\n";

					foreach ($locationName['PlaceName'] as $placeName){
						if(in_array($placeName['language'], $this->languages)){
							$temp['name_'.$placeName['language']] = $placeName['description'];
						}
					}
					foreach ($this->languages as $language){
						if(!isset($temp['name_'.$language])){
							$temp['name_'.$language] = $temp['name'];
						}
						
					}
					
					//Hot fix for brabant wallon
					if(strcmp($temp['uuid'], "IEX-fax_T-Ox3UpLaN8aoA") == 0) {
						$temp['name_fr'] = 'Province du Brabant wallon';
						$temp['name_nl'] = 'Beleef Waals-Brabant';
					}
					
					$locations[$temp['level']] = $temp;
					
					$this->updatePlace($locationName, $temp['type'], $temp['level']);
				}
			}

			if(isset($data['location'])){
				$this->getZipCode($version['Version']['exifLatitude'], $version['Version']['exifLongitude'], $locations);
				//$this->addGoogleLocationsInformations( $version['Version']['exifLatitude'], $version['Version']['exifLongitude'], $locations);				
			}

// 			var_dump($locations);
// 			exit;
			
			$data['locations'] = array_values($locations);
			//'http://maps.googleapis.com/maps/api/geocode/json?latlng=40.714224,-73.961452&sensor=false';


			$data['Keywords'] = array();
			$keywordAdded = [];
			foreach($version['Keyword'] as $keyword){


				//echo $keyword['modelId']."\n";
				//var_dump($this->keywords);
				//exit;

				if( array_key_exists( $keyword['modelId'], $this->keywords) ){
					$currentId = $keyword['modelId'];
					$encodedUuid = $keyword['encodedUuid'];
					while ($currentId > 0) {
						//echo "$currentId\n";
						$data['Keywords'][] =  $this->translateKeyword($this->keywords[$currentId]);
						$keywordAdded[] = $currentId;
 						$currentId = $this->keywords[$currentId]['parentId'];
						if( ! array_key_exists( $currentId, $this->keywords) || in_array($currentId, $keywordAdded)){
							break;
						}
					}
				}
			}


			$data['Stack'] = array();
			if($version['Version']['stackUuid']) {
				$stackVersions = $this->Version->findAllByStackuuid($version['Version']['stackUuid'], array('uuid', 'encodedUuid'), array('unixImageDate'));
				foreach($stackVersions as $stackVersion){
					if($this->putToS3($stackVersion['Version']['uuid'], $stackVersion['Version']['encodedUuid'])){
						$data['Stack'][] = $stackVersion['Version']['encodedUuid'];
					}
				}
			}
			else {
				if($this->putToS3($version['Version']['uuid'], $version['Version']['encodedUuid'])){
					$data['Stack'][] = $version['Version']['encodedUuid'];
				}
			}

			//json_encode($data);
			
			//var_dump($data);

			$this->putObjectToElasticSearch($data, "version", $version['Version']['encodedUuid']);

			//$this->out($data);
			//break;
		}

		return count($versions);

	}

	
	private function updatePlace($place, $type, $level){
		if(!isset($this->places[$place['Place']['modelId']])){
			$this->places[$place['Place']['modelId']] = true;
			
			$data = [
				'name' => $place['Place']['defaultName'],
				'model_id' => $place['Place']['modelId'],
				'type' => $type,
				'level' => $level,
				'uuid' => $place['Place']['encodedUuid'],
			];
			
			foreach ($place['PlaceName'] as $placeName){
				if(in_array($placeName['language'], $this->languages)){
					$data['name_'.$placeName['language']] = $placeName['description'];
				}
			}
			//var_dump($place['Place']['defaultName']);
			//var_dump($place['Place']['centroid']);
			//var_dump(preg_split("/[\{,\s\}]+/", $place['Place']['centroid']));
			//exit;
			$splited = preg_split("/[\{,\s\}]+/", $place['Place']['centroid']);
			
			if(count($splited) == 4){
				$lng = $splited[1];
				$lat = $splited[2];
			}
			else {
				$lng = $splited[0];
				$lat = $splited[1];
			}
			
			
			$data['geometry'] = [
					'bounds' => [
							'northeast' => [
									'lat' => $place['Place']['maxLatitude'],
									'lng' => $place['Place']['maxLongitude'],
							],
							'southwest' => [
									'lat' => $place['Place']['minLatitude'],
									'lng' => $place['Place']['minLongitude'],
							]
					],
					'location' => [
							'lat' => $lat,
							'lng' => $lng,
					]
			];
			
			$this->putObjectToElasticSearch($data, "place", $place['Place']['encodedUuid']);
			
		}
	}

	private function translateKeyword($keyword){
		$out = [
			'id' => $keyword['modelId'],
			'uuid' => $keyword['encodedUuid'],
			'name' => $keyword['name']
		];
		
		foreach ($this->languages as $language){
			$out['name_'.$language] = I18n::translate(Normalizer::normalize($keyword['name']), null, null, I18n::LC_MESSAGES, null, $language, null);
		}
				
		return $out;
	}

	private function getKeywords(){
		$rootKeywordName = Configure::read('rootKeywordName');

		$root = $this->Keyword->findByName($rootKeywordName);

		$keywords = $this->getAllSubKeywords($root['Keyword']['modelId']);

		$fh = fopen("Locale".DS."default.pot", 'w');
		fwrite($fh, "# LANGUAGE translation of https://github.com/theus77/Aperture2Global\n");
		fwrite($fh, "# Copyright 2015 Theus Deka <me@theus.be>\n");
		fwrite($fh, "#\n");
		fwrite($fh, "#, fuzzy\n");
		fwrite($fh, "msgid \"\"\n");
		fwrite($fh,  "msgstr \"\"\n");
		fwrite($fh,  "\"Project-Id-Version: Aperture2Global\\n\"\n");
		fwrite($fh,  "\"PO-Revision-Date: YYYY-mm-DD HH:MM+ZZZZ\\n\"\n");
		fwrite($fh,  "\"Last-Translator: NAME <EMAIL@ADDRESS>\\n\"\n");
		fwrite($fh,  "\"Language-Team: LANGUAGE <EMAIL@ADDRESS>\\n\"\n");
		fwrite($fh,  "\"MIME-Version: 1.0\\n\"\n");
		fwrite($fh,  "\"Content-Type: text/plain; charset=utf-8\\n\"\n");
		fwrite($fh,  "\"Content-Transfer-Encoding: 8bit\\n\"\n");
		fwrite($fh,  "\"Plural-Forms: nplurals=INTEGER; plural=EXPRESSION;\\n\"\n");

		foreach ($keywords as $keyword) {
				$key = $keyword['name'];
				$value = $keyword['name'];
		    $key = addslashes($key);
		    $value = addslashes($value);
		    fwrite($fh, "\n");
				fwrite($fh, "#: ".$keyword['modelId']."\n");
		    fwrite($fh, "msgid \"$key\"\n");
		    fwrite($fh, "msgstr \"\"\n");
		    
		    
		    $keyword = [
		    		'modelId' => $keyword['modelId'],
		    		'name' => $keyword['name'],
		    		'uuid' => $keyword['encodedUuid'],
		    ];
		    
		    	
		    foreach ($this->languages as $language){
		    	$keyword['name_'.$language] = I18n::translate(Normalizer::normalize($keyword['name']), null, null, I18n::LC_MESSAGES, null, $language, null);
		    }
		    
		    $this->putObjectToElasticSearch($keyword, 'keyword', $keyword['uuid']);
		}

		fwrite($fh, "\n");
		fclose($fh);


		return $keywords;
	}



	private function getAllSubKeywords($keywordId){
		$findOptions = array(
				'fields' => array('name', 'parentId', 'modelId', 'encodedUuid'),
				'conditions' => array(
						'parentid' => array($keywordId)
				)
		);

		//$parentids = array($keywordId);
		$found = 1;
		$keywords = array();

		$nameIndex = array();

		while($found){
			$found = false;
			//echo "found childs for :\n";
			//var_dump($findOptions['conditions']['parentid']);
			$results = $this->Keyword->find('all', $findOptions);
			$findOptions['conditions']['parentid'] = array();
			foreach ($results as $keyword) {
				$found = true;
				$findOptions['conditions']['parentid'][] = $keyword['Keyword']['modelId'];
				$keywords[$keyword['Keyword']['modelId']] = $keyword['Keyword'];
				
				if(isset($nameIndex[$keyword['Keyword']['name']])){
					//echo "found dup: ".$keyword['Keyword']['name'];
					$parent = $keywords[$keyword['Keyword']['parentId']];
					$keywords[$keyword['Keyword']['modelId']]['name'] .= " (".$parent['name'].")";

					echo "found dup: ".$keywords[$keyword['Keyword']['modelId']]['name'];

					if($nameIndex[$keyword['Keyword']['name']] > 0){
						$other = &$keywords[$nameIndex[$keyword['Keyword']['name']]];
						$parent = $keywords[$other['parentId']];
						$other['name'] .= " (".$parent['name'].")";
						echo " with : ".$keywords[$nameIndex[$keyword['Keyword']['name']]]['name'];
						$nameIndex[$keyword['Keyword']['name']]  = 0;
					}
					echo "\n";


				}
				else{
					$nameIndex[$keyword['Keyword']['name']] = $keyword['Keyword']['modelId'];
				}
			}

			//exit;

		}

		//$keywords[$keywordId] = $this->Keyword->findByModelid($keywordId)['Keyword']['name'];
		//print_r($keywords); exit;
		return $keywords;
	}


	public function putObjectToElasticSearch($data, $type, $id){

// 		$client = new Client();

		$params = [
				'index' => self::INDEX,
				'type' => $type,
				'id' => $id,
				'body' => $data
		];

		//dump
		// Document will be indexed to my_index/my_type/my_id
		$response = $this->client->index($params);

		//var_dump($data);





		/*$searchParams['index'] = 'global';
		$searchParams['type']  = 'version';*/



		/*$data_json = json_encode($data);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, Configure::read('elasticSearchUrl').$type."/".$id);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($data_json)));
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
		curl_setopt($ch, CURLOPT_POSTFIELDS,$data_json);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response  = curl_exec($ch);
		$this->out($response);
		curl_close($ch);*/
	}

	
	private function initIndex(){
		$version = json_decode(file_get_contents('mapping.json'), true);
		
		$this->client->indices()->create(['index' => self::INDEX.'_v1']);
		
		$this->client->indices()->putMapping([
				'index' => self::INDEX.'_v1',
				'type' => 'version',
				'body' => $version
		]);/**/
		

		$this->client->indices()->putAlias([
				'name' => self::INDEX,
				'index' => self::INDEX.'_v1'
		]);
		
	}

	
	
	private function allowAccessToES(){
		$this->out("Checking IP....");
		
		$externalContent = file_get_contents('http://checkip.dyndns.com/');
		preg_match('/Current IP Address: \[?([:.0-9a-fA-F]+)\]?/', $externalContent, $m);
		$externalIp = $m[1];


		$this->out("Check ES allow access to IP: $externalIp");
		
		
		$client  = new ElasticsearchServiceClient(Configure::read('awsClientConfig'));
		
		$config = $client->updateElasticsearchDomainConfig([
				'DomainName' => Configure::read('awsESDomainName'),
		]);
		
		$accessPolicies = json_decode($config['DomainConfig']['AccessPolicies']['Options'], true);
		
		foreach ($accessPolicies['Statement'] as $statement){
			if( isset($statement['Condition']) && 
					isset($statement['Condition']['IpAddress']) && 
					isset($statement['Condition']['IpAddress']['aws:SourceIp']) && 
					strcmp($statement['Condition']['IpAddress']['aws:SourceIp'], $externalIp) == 0 ){
				$this->out("$externalIp has already access");
				return;
			}
		}
		
		$accessPolicies['Statement'][] = [
				'Sid' => '',
				'Effect' => 'Allow',
				'Principal' => [ 'AWS' => '*' ],
				'Action' => 'es:*',
				'Condition' => ['IpAddress' => [ 'aws:SourceIp' => [$externalIp]]],
				'Resource' => $accessPolicies['Statement'][0]['Resource'],
				
		];
		
		$config = $client->updateElasticsearchDomainConfig([
				'DomainName' => Configure::read('awsESDomainName'),
				'AccessPolicies' => json_encode($accessPolicies),
		]);
		
		$this->out("Access policies updated");

		$this->out("$externalIp is now registered to AWS, It will take some times to be enabled (around 20 minutes)");
	}
	
	public function main() {
		
		if(null !== Configure::read('awsESHosts')) {	
			$this->allowAccessToES();
		
			$this->client = ClientBuilder::create()           // Instantiate a new ClientBuilder
				->setHosts(Configure::read('awsESHosts'))      // Set the hosts
				->build();              // Build the client object
		}
		else {			
			$this->client =  ClientBuilder::create()           // Instantiate a new ClientBuilder
				->build(); 
		}

		$this->languages = Configure::read('languages');
		$this->googleApiKey = Configure::read('googleApiKey');
		$this->places = [];
		$this->locations = [];
		
		
		
		while(true) {
			try {			
				$this->client->indices()->exists(['index' => self::INDEX]);
			}
			catch (Forbidden403Exception $e) {
				$this->out("Access forbidden to ES, try again in 3 minutes ...");
				sleep(180);
				continue;
			}
			break;
		}
		
		if(!$this->client->indices()->exists(['index' => self::INDEX])){
			$this->initIndex();
		}		
		

		$this->keywords = $this->getKeywords();

		//echo $endpoint;

		//exit();
		
		$this->s3 = new S3Client(Configure::read('awsClientConfig'));

		


		
		
		//echo __('Bâtiment');
// 		$params = ['index' => self::INDEX];
		//$response = $this->client->indices()->delete(['index' => self::INDEX]);

		
		//$exit;,
		

// 		$result = $this->s3->listBuckets();

// 		foreach ($result['Buckets'] as $bucket) {
// 			echo $bucket['Name'] . "\n";
// 		}

		
		
		
//exit;
		$this->out('Start indexing series');
		
		$page = 0;
		while($this->updateVersionsInfo($page++));
		
		
		
		//$mapping['index']['mappings']['version']['properties']['Keywords']['properties']['name_en']['analyzer'] = 'english';
		//$mapping['index']['mappings']['version']['properties']['Keywords']['properties']['name_fr']['analyzer'] = 'french';
		
		/*$this->client->indices()->putMapping([
				'index' => 'index', 
				'type' => 'version', 
				'body' => [
						'version' => [
								'properties' => [
										'Keywords' => [
												'name_en' => [
													'type' => 'string',
													'analyzer' => 'english'
												]
										]
								]
						]
				]
		]);*/

		
		
	}
}
