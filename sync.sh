#!/usr/bin/env bash

ssh -L 9200:localhost:9200 -N ec2-user@ec2-54-93-53-225.eu-central-1.compute.amazonaws.com&

for D in /Volumes/*; do
    if [ -d "${D}" ]; then
        for L in ${D}/*.aplibrary; do
                    if [ -d "${L}" ]; then
                        echo "Found library ${L}"   # your processing here
                        cd ${L}
                        git clone https://github.com/theus77/Aperture2Global.git
                        cd Aperture2Global
                        git fetch
                        git pull
                        git reset --hard
                        curl -sS https://getcomposer.org/installer | php
                        php composer.phar selfupdate
                        php composer.phar update
                        Console/cake index
                    fi
                done
    fi
done

cd /Users/SimGV/Pictures/Global\ View\ Aerien.aplibrary/Aperture2Global
git fetch
git pull
git reset --hard
curl -sS https://getcomposer.org/installer | php
php composer.phar selfupdate
php composer.phar update
Console/cake index
