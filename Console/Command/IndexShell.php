<?php

use Elasticsearch\Client;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Aws\ElasticsearchService\ElasticsearchServiceClient;
use Elasticsearch\ClientBuilder;
// Can be called by "Console/cake index"

App::uses('Shell', 'Console');

App::import('Controller', 'Aperture');

class IndexShell extends AppShell {

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

	private function buildFindversionOptions(){

		return array(
				// 				'limit' => 20,
				'conditions' => array(
						'Version.isFlagged' => 1,
						'Version.showInLibrary' => 1,
						'Version.isHidden' => 0,
						'Version.isInTrash' => 0,
						'Version.mainRating >=' => 0
				),
				'contain' => array(
						'PlaceForVersion' => array(
								'fields' => array('placeId')
						),
						'Keyword' => array(
								'fields' =>  array('name', 'modelId'),
								/*'conditions' => [
										'placeId' => array_keys($this->keywords)
									]/**/
						)),
				'order' => array('Version.imageDate DESC'),
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

		return true;
		$imageProxy = $this->ImageProxyState->findByVersionuuid($uuid);

		$filePath = APP."Thumbnails".DS.$imageProxy['ImageProxyState']['thumbnailPath'];

		if($imageProxy && file_exists($filePath)){

			try {
				$result = $this->s3->headObject([
						'Bucket' => 'global-previews',
						'Key'    => $encodedUuid
				]);/**/
			} catch (S3Exception $e) {
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
			echo "file not found for uuid $uuid: $filePath \n";
		}
		return false;

	}


	private function getLocality($lat, $lng, $language){

		$out = [
			//'model_id' => $modelId,
			'language' => $language
		];

		// google map geocode api url
		$url = "https://maps.googleapis.com/maps/api/geocode/json?latlng=$lat,$lng&sensor=false&language=$language";


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
				return false;
		}
	}

	private function addGoogleLocationsInformations($lat, $lng, &$locations) {
		foreach ($this->languages as $language){
			$localities = $this->getLocality($lat, $lng, $language);
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
							var_dump($locality);
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
						$locations[$type]['placeId'] = $locality['place_id'];
						$locations[$type]['name_'.$language] = $locality['address_components'][0]['long_name'];
					}

				}
			}

		}
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

	public function updateVersionsInfo(){
		$this->out('Indexing versions...');
// 		$aperture = new ApertureConnectorAppController()rtureController();
// 		$aperture->constructClasses(); //I needed this in here for more complicated requiring component loads etc in the Controller

// 		$findversionOptions = $aperture->buildFindversionOptions();

		$findversionOptions = $this->buildFindversionOptions();


		$versions = $this->Version->find('all', $findversionOptions);
		foreach ($versions as $version){

			$data['uuid'] = $version['Version']['encodedUuid'];
			$data['name'] = $version['Version']['name'];
			$data['date'] = $version['Version']['unixImageDate'];
			$data['width'] = $version['Version']['masterWidth'];
			$data['height'] = $version['Version']['masterHeight'];
			$data['location'] = array(
					'lat' => $version['Version']['exifLatitude'],
					'lon' => $version['Version']['exifLongitude']
			);
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
						'level' => 3*$locationName['Place']['type'],
						'modelId' => $locationName['Place']['modelId'],
						'name' => $locationName['Place']['defaultName'],
					);

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
						case 16:
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
					$locations[$temp['level']] = $temp;

				}
			}

			$this->addGoogleLocationsInformations( $version['Version']['exifLatitude'], $version['Version']['exifLongitude'], $locations);

			$data['locations'] = 	$locations;
			//'http://maps.googleapis.com/maps/api/geocode/json?latlng=40.714224,-73.961452&sensor=false';


			$data['Keywords'] = array();
			foreach($version['Keyword'] as $keyword){


				//echo $keyword['modelId']."\n";
				//var_dump($this->keywords);
				//exit;

				if( array_key_exists( $keyword['modelId'], $this->keywords) ){
					$currentId = $keyword['modelId'];
					while ($currentId > 0) {
						//echo "$currentId\n";
						$data['Keywords'][] =  $this->translateKeyword($this->keywords[$currentId]);
						$currentId = $this->keywords[$currentId]['parentId'];
						if( ! array_key_exists( $currentId, $this->keywords) ){
							break;
						}
					}
				}
			}


			$data['Stack'] = array();
			if($version['Version']['stackUuid']) {
				$stackVersions = $this->Version->findAllByStackuuid($version['Version']['stackUuid'], array('uuid', 'encodedUuid', 'name', 'unixImageDate'), array('unixImageDate'));
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

			$this->putObjectToElasticSearch($data, "version", $version['Version']['encodedUuid']);

			//$this->out($data);
			break;
		}



	}


	private function translateKeyword($keyword){
		$out = [
			'id' => $keyword['modelId'],
			'name' => $keyword['name']
		];
		echo $keyword['name']."\n";
		foreach ($this->languages as $language){
			Configure::write('Config.language', $language);
			echo "   -$language: ".__($keyword['name'])."\n";
			$out['name_'.$language] = I18n::translate($keyword['name'], null, null, I18n::LC_MESSAGES, null, $language, null);
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
		}

		fwrite($fh, "\n");
		fclose($fh);


		return $keywords;
	}



	private function getAllSubKeywords($keywordId){
		$findOptions = array(
				'fields' => array('name', 'parentId', 'modelId'),
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
				'index' => 'index',
				'type' => 'version',
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


	public function main() {


		/*$amazones = new ElasticsearchServiceClient([
				'version' => 'latest',
				'region'  => 'us-west-2'
		]);*/

		//$hosts = ['https://search-globalview-64tdo3cxwpdh5vwkwlerhgac2e.us-west-2.es.amazonaws.com:443/'];


		$this->client = ClientBuilder::create()           // Instantiate a new ClientBuilder
// 			->setHosts(Configure::read('awsESHosts'))      // Set the hosts
			->build();              // Build the client object

		//$this->client = new Client();

		$this->languages = Configure::read('languages');
		$this->googleApiKey = Configure::read('googleApiKey');


		$this->keywords = $this->getKeywords();

		//echo $endpoint;

		//exit();

		$this->s3 = new S3Client([
				'version' => 'latest',
				'region'  => 'eu-central-1'
		]);


		//echo __('Bâtiment');
		$params = ['index' => 'index'];
		//$response = $this->client->indices()->delete($params);
		//$exit;

// 		$result = $this->s3->listBuckets();

// 		foreach ($result['Buckets'] as $bucket) {
// 			echo $bucket['Name'] . "\n";
// 		}

		$this->out('Start indexing series');
		$this->updateVersionsInfo();
	}
}
