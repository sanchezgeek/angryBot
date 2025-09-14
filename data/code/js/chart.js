class ChartManager {
  constructor(data) {
    this.chart = null
    this.xspan = null
    this.klines = null
    this.startPoint = null
    this.lineSeries = null
    this.isUpdatingLine = false
    this.isHovered = false
    this.isDragging = false
    this.dragStartPoint = null
    this.dragStartLineData = null
    this.lastCrosshairPosition = null
    this.candleseries = null
    this.selectedPoint = null //null/0/1
    this.hoverThreshold = 0.01
    this.domElement = document.getElementById('tvchart')
    this.initializeChart()
  }

  initializeChart() {
    const chartOptions = {
      layout: { textColor: 'black', background: { type: 'solid', color: 'white' } },
      crosshair: {
        mode: LightweightCharts.CrosshairMode.Normal,
      },
    }
    this.chart = LightweightCharts.createChart(this.domElement, chartOptions)
    this.candleseries = this.chart.addSeries(LightweightCharts.CandlestickSeries, {
      upColor: '#26a69a',
      downColor: '#ef5350',
      borderVisible: false,
      wickUpColor: '#26a69a',
      wickDownColor: '#ef5350',
    })

    let data
    jQuery.ajax({
      url: './data.json',
      async: false,
      dataType: 'json',
      success: function (response) {
        data = response
      },
    })
    this.candleseries.setData(data)

    const lineSeries = this.chart.addSeries(LightweightCharts.LineSeries, { color: '#2962FF' })
    lineSeries.setData([
      { value: 107000, time: data[0].time },
      { value: 107000, time: data[data.length - 1].time },
    ])

    this.chart.timeScale().fitContent()
  }
}
