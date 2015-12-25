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
			'Byline',
			'CopyrightNotice',
			'CiEmailWork',
			'UsageTerms',
			'City',
			'SubLocation',
			'ObjectName',
			'MasterLocation',
			'AspectRatio',
			'PixelSize',
			'ProjectName',
			'LensModel',
			'Model',
			'Copyright',
			'Artist',
			'UserComment',
			'Altitude',
			'Longitude',
			'Latitude'
	];

	private $client;
	private $s3;

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
								'fields' =>  array('name'),
						)),
				'order' => array('Version.imageDate DESC'),
				//'fields' => array('Version.imageDate', 'Version.encodedUuid', 'Version.name', 'Version.exifLatitude', 'Version.exifLongitude', 'Version.unixImageDate', 'Version.stackUuid'),
				//'group' => array( 'Version.imageDate', 'Version.encodedUuid', 'Version.name', 'Version.exifLatitude', 'Version.exifLongitude', 'Version.unixImageDate', 'Version.stackUuid'),
		);

	}

	public function initialize()
	{
		parent::initialize();
		$this->loadModel('Gallery');
		$this->loadModel('ApertureConnector.Place');
		$this->loadModel('ApertureConnector.Version');
		$this->loadModel('ApertureConnector.IptcProperty');
		$this->loadModel('ApertureConnector.OtherProperty');
		$this->loadModel('ApertureConnector.ExifStringProperty');
		$this->loadModel('ApertureConnector.ExifNumberProperty');
		$this->loadModel('ApertureConnector.ImageProxyState');
		

	}

	private function putToS3($uuid, $encodedUuid){

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
	
	
	private function getZip(){
		
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



			foreach($version['PlaceForVersion'] as $location){
				
				$locationNames = $this->Place->find('all', [
						
					'conditions' => [
							'modelId' => $location['placeId'] 
					],
					'contain' => array(
						'PlaceName' /*=> array(
								'fields' => array('language', 'description'),
						)*/),
				]);
				foreach ($locationNames as $locationName){
					
					switch ($locationName['Place']['type']){
						case 1:
							$index = 'country';
							break;
						case 2:
							$index = 'region';
							break;
						case 16:
							$index = 'city';
							$data['zip'] = $this->getZip($locationName['Place']['modelId'], $locationName['Place']['centroid']);
							break;
						case 45:
							$index = 'neighborhood';
							break;
						default:
							$index = 'location_'.$locationName['Place']['type'];
							break;
					}
					
					$data[$index] = $locationName['Place']['defaultName'];
					foreach ($locationName['PlaceName'] as $placeName){
						if(in_array($placeName['language'], $this->languages)){
							$data[$index.'_'.$placeName['language']] = $placeName['description'];
						}
					}
				}
			}
			
			'http://maps.googleapis.com/maps/api/geocode/json?latlng=40.714224,-73.961452&sensor=false';


			$data['Keywords'] = array();
			foreach($version['Keyword'] as $keyword){
				$temp = ['name' => $keyword['name']];
				foreach ($this->languages as $language){
					$temp['name_'.$language] = I18n::translate($keyword['name'], null, null, I18n::LC_MESSAGES, null, $language, null);
				}
				
				$data['Keywords'][] =  $temp;
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

			$data['Properties'] = array();

			$this->IptcProperty->contain('UniqueString');
			$props = $this->IptcProperty->findAllByVersionid($version['Version']['modelId']);
			foreach($props as $prop){
				if(in_array($prop['IptcProperty']['propertyKey'], $this->properties))
					$data['Properties'][$prop['IptcProperty']['propertyKey']] = $prop['UniqueString']['stringProperty'];
			}

			$this->OtherProperty->contain('UniqueString');
			$props = $this->OtherProperty->findAllByVersionid($version['Version']['modelId']);
			foreach($props as $prop){
				if(in_array($prop['OtherProperty']['propertyKey'], $this->properties))
					$data['Properties'][$prop['OtherProperty']['propertyKey']] = $prop['UniqueString']['stringProperty'];
			}

			$this->ExifStringProperty->contain('UniqueString');
			$props = $this->ExifStringProperty->findAllByVersionid($version['Version']['modelId']);
			foreach($props as $prop){
				if(in_array($prop['ExifStringProperty']['propertyKey'], $this->properties))
					$data['Properties'][$prop['ExifStringProperty']['propertyKey']] = $prop['UniqueString']['stringProperty'];
			}

			$props = $this->ExifNumberProperty->findAllByVersionid($version['Version']['modelId']);
			foreach($props as $prop){
				if(in_array($prop['ExifNumberProperty']['propertyKey'], $this->properties))
					$data['Properties'][$prop['ExifNumberProperty']['propertyKey']] = $prop['ExifNumberProperty']['numberProperty'];
			}

			//json_encode($data);

			$this->putObjectToElasticSearch($data, "version", $version['Version']['encodedUuid']);

			//$this->out($data);
			//break;
		}



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
		
		//echo $endpoint;
		
		//exit();
		
		$this->s3 = new S3Client([
				'version' => 'latest',
				'region'  => 'eu-central-1'
		]);

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
