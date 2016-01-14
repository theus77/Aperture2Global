#!/usr/bin/env bash

if [ ! -d "./Aperture2Global" ]
then
	echo Get Aperture2Global
	git clone https://github.com/theus77/Aperture2Global.git
	cd Aperture2Global
	curl -sS https://getcomposer.org/installer | php
else
	cd Aperture2Global
	echo Update Aperture2Global
	git fetch origin
	php composer.phar selfupdate
fi

echo Launch synchro
php composer.phar update
Console/cake index

