### Intro

Stack: PHP 8.4 | Symfony | PostgreSQL | VueJS

The bot trades on ByBit on USDT pairs. It was originally developed for manual position opening, but eventually it developed into automatic position opening. At the moment, only SHORT positions are automatically opened, but soon there will be LONG positions. 
What is there?
1) Automatic opening of positions
   
    There is only one strategy now: opening SHORT positions on strong upward turns (wicks)
    To be sure, they are checked:
    - CurrentPrice-to-ATH ratio 
     
        Example: ATH=1000, currentPrice = 900 => confidenceRate = 0.9
    - FundingRate
   
        Does not open at strongly negative funding. also affects confidenceRate)
    - Instrument age
   
        For coins with a small age, the confidenceRate will be lower
    - ... There are plans to check the MarketStructure break (Smart Money) ...
2) SL placement based on a simple technical analysis (1D ATR[7-10])
3) LockInProfit
    - creation of an SL-grid before the position opening price after passing a certain distance
    - periodic profit taking after passing a certain distance
4) Preventing the liquidation of positions when trading on UTA (when unrealized profits of each position are used to maintain liquidation of all other positions)

    In critical situations, the bot will automatically close positions in small parts after the price passes a certain threshold.

    For example, 95% of distance between entry and liquidation passed - 2%-SL will be created, 50% passed - 20% of the position
   
    This should not happen, because when opening positions stops are placed, but there is such a mechanism for insurance.
5) Hedging
   
    Hedges positions (manually at the moment, but there are plans for automatic hedging as a separate LockInProfit strategy)

At the moment, the bot is showing good results if you don't trade with your hand and just trust what it does. But we need to maximize profits.

The bot runs on Mac/Linux and is controlled via the console. Thus, there is a LARGE set of commands for trading, but which at the moment are not documented and are convenient only for me.
Accordingly, the bot needs a UI (at the moment there is only an initial stage, since I am a backend developer).
Based on all this, there is a VERY HUGE need for
1) People who know more about the market than I do and will be able to suggest new strategies for implementation.
2) QA, who will be able to debug it all.
3) Frontend developers who can come up with a UI for all this goodness.

### Contacts

If you are ready to cooperate, ready to try this bot in the current state or you need an explanation of how it works - contact me by email `sanchezgeek@gmail.com`

You can also try using it yourself if you have the basic skills of backend development. The commands for launching and running the tests are presented below.

### Donations
`USDT (TRC-20)`: TVej4oL2prmJci5SceGqGyNtjYM87yRunE

`BTC`: bc1qcst3hy4heexz24086fkr7dp04xsdmrf2jmqcnq

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
./bin/console p:opened -ccomment
# save opened positions cache (to compare with previous state)
```

```shell
./bin/console p:opened --diff=last --sort-by=max-negative-change --update-interval=15
# opened positions and diff with previously saved cache
```

```shell
./bin/console o:t sell --symbol=ALPINE
# shows orders on ALPINEUSDT pair for SHORT position
```

Open position
```shell
./bin/console p:open sell --symbol=ALPINE -p10%
# will open ALPINEUSDT SHORT (10% of deposit will be used as initial margin [size calculated on 100xLeverage]).
# stops will be automatically created based on current 1D-ATR[7]
```

Hedge
```shell
./bin/console p:hedge:open --symbol=ALPINE -p10%
# hedges 10% of ALPINEUSDT position. If there is SHORT position as main - will open LONG-position
```

Buy Grid
```shell
# for LONG-position:
make sf c="buy:grid buy -f-300% -t500% 0.001 50% --wOO --fB"
# for SHORT-position:
make sf c="buy:grid sell -f-300% -t500% 0.001 655 --wOO --fB"

# will create orders respectively to specified positionSide ('buy' or 'sell')
# 0.001  [required]      - each order volume
# 50%    [required]      - step (every 0.5% of price change)

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
./bin/instances/mac/bin/watchNotifications notifications # watch notifications
```


### Testing
```shell
make sf c='doctrine:database:create --env="test"' && make sf c='d:m:m --env="test"' # to create test db
```
```shell
make test # to run tests
```
