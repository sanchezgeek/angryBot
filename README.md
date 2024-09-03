### Start
Option 1:
- Add actual secrets to `.env.build`
- Run `./bin/first_run`

Option 2 (if you want to load secrets from some other location):
- Run `./bin/first_run <path_to_file_with_your_secrets>`



### Useful commands
```shell
make sf c="balance:info"
```
```shell
make b-info # "LONG"-position info
```
```shell
make s-info # "SHORT"-position info
```
```shell
make stats # current stats (balance + both `s-info` and `b-info`)
```
```shell
make l-m-info t=61000 # for LONG-position
make s-m-info t=61000 # for SHORT-position
# show difference in position size and entry price while price moves towards specified price (after executing BuyOrders)
```

Buy Grid
```shell
# for LONG-position:
make sf c="buy:grid buy -f-300% -t500% 0.001 655 --wOO --fB"
# for SHORT-position:
make sf c="buy:grid sell -f-300% -t500% 0.001 655 --wOO --fB"

# will create orders respectively to specified positionSide ('buy' or 'sell')
# 0.001  [required]      - each order volume
# 655    [required]      - step

# --fB   [optional]      - "force" buy
# might be useful if hedge support size already enough to hold main position on long distance (in such case buy orders will be skipped)
# (skip also actual for some other cases)

# --wOO  [optional]     - @see same option in the section below
```

SL Grid
```shell
# for LONG-position:
make sf c="sl:grid buy -f-300% -t-500% 10% --bM --wOO -c15"
# for SHORT-position:
make sf c="sl:grid sell -f-300% -t-500% 10% --bM --wOO -c15"

# will create 15 SL's respectively to specified positionSide ('buy' or 'sell')
# -c     [optional]        - orders quantity  (specified 10% will be divided to 15 equal pieces)
#        (default = 10)

# --bM   [optional]        - stops will be force executed by market   
#        (default behaviour: stops will act as 'conditional SL' [and will be pushed to exchange when delta betwen current price and SL.price reached some treshold])
#        another option: `... -d10 ...` => will create stops with triggerDelta = 10

# --wOO  [optional]        - to not create opposite BuyOrders
#        (default befaviour: creates buy orders with some distance on reverse price move)
```

Other
```shell
make sh
# sh to container (e.g. for remove orders with commands provided after execute `buy:grid` or `sl:grid`)
```
```shell
make stop       # stop containers
make dc-stop    # PERMANENTLY stop containers (containers won't start even after reboot)
make restart    # restarts containers (e.g. after download updates)
make refresh    # clean logs (should be run only after `make stop`)
```

### Monitoring (without alarm)
```shell
make crit
# CRITICAL errors (e.g fatal errors on compile)
```
```shell
make conn_err
# connection errors (e.g "timeout", "bad reqv.window", etc.)
```
```shell
make err
# "application runtime" errors (something isn't right while doing job)
```
```shell
make sf c="queues:check"
# to check queues not overwhelmed (should be run only after "stop")
```

### Monitoring (with alarm)
```shell
./bin/watchLogs crit       # watch critical errors with alarm sound notification
./bin/watchLogs conn_err   # watch connection errors with alarm sound notification
./bin/watchLogs err        # watch app errors with alarm sound notification
```
