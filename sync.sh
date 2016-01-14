#!/usr/bin/env bash

curl -sS https://getcomposer.org/installer | php
php composer.phar selfupdate
git fetch origin
php composer.phar update
Console/cake index

