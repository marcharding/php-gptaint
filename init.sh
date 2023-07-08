# see https://stackoverflow.com/questions/5750450/how-can-i-print-each-command-before-executing
# set -o xtrace
trap 'echo -e "\e[0;32m" && echo -ne $(date "+%Y-%m-%d %H:%M:%S") && echo " >> Executing: $BASH_COMMAND" && echo -e "\e[0m"' DEBUG
mysql -h127.0.0.1 -uroot -e "create database application"
composer install
trap - DEBUG
