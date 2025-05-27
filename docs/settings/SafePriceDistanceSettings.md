##


### safePriceDistance.percent
Assume you want to add to your short position but you're not sure that position liquidation price in result will be on safe distance with ticker price.
So in this case you can use this setting.
Example:
1) Symbol: BNB. Price: 630
2) Current position liquidation = 1100. So current distance = 470.
3) You want to bot do buy only when distance between further position liquidation and ticker price equals 470.
    This is ~60% of current price.

Thus, your settings and grid:

```shell
- ./bin/console settings:edit safePriceDistance.percent --symbol=BNB --value=60
```
```shell
- ./bin/console buy:grid --symbol=BNB sell -f628 -t600 0.001 40% --oAB --wOO
```



Applicable when:
1) If you trade on cross-margin on many symbols simultaneously.

   price of every asset changes all the time, so liquidation price of all your positions also changes

