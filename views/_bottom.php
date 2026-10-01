  </main>
</div>
</div>
<script>window.NOC = { lang: <?= json_encode($GLOBALS['LANG']) ?>, csrf: <?= json_encode($_SESSION['csrf'] ?? '') ?>, t: <?= json_encode(['rx' => A('Download'), 'tx' => A('Upload'), 'noData' => A('Ni podatkov za to obdobje'), 'cpu' => 'CPU', 'mem' => A('Pomnilnik'), 'gw' => A('Prehod'), 'ext' => A('Internet'), 'loss' => A('izguba'), 'temp' => A('Temperatura'), 'conns' => A('Povezave'), 'tiger' => A('Ping s strežnika'), 'copied' => A('Kopirano')], JSON_UNESCAPED_UNICODE) ?> };</script>
<script src="/assets/vendor/uPlot.iife.min.js"></script>
<script src="/assets/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
