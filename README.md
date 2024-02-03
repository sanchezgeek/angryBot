### Description
Trading bot.

### Start
```shell
make start
make composer "c=install" # to install deps
make sf c="d:m:m" # to create db-schema
```

###### Maybe need after dependencies installed
```shell
make restart
```
### Testing
```shell
make sf c='doctrine:database:create --env="test"' && make sf c='d:m:m --env="test"' # to create test db
```
```shell
make test # to run tests
```