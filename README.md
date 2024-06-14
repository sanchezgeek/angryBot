### Description
Trading bot.

### Start
```shell
sudo apt install make
sudo make build ENV_BUILD_FILEPATH=...
sudo make start
sudo make composer "c=install" # to install deps
sudo make sf c="doctrine:database:create" && sudo make sf c="d:m:m" # to create db-schema
```

###### Maybe need after dependencies installed
```shell
sudo make restart
```
### Testing
```shell
sudo make sf c='doctrine:database:create --env="test"' && sudo make sf c='d:m:m --env="test"' # to create test db
```
```shell
sudo make test # to run tests
```
