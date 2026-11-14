  </main>
</div>
</div>
<script<?= nonce() ?>>window.NOC = { lang: <?= json_encode($GLOBALS['LANG']) ?>, csrf: <?= json_encode($_SESSION['csrf'] ?? '') ?>, t: <?= json_encode(['rx' => A('Download'), 'tx' => A('Upload'), 'noData' => A('Ni podatkov za to obdobje'), 'cpu' => 'CPU', 'mem' => A('Pomnilnik'), 'gw' => A('Prehod'), 'ext' => A('Internet'), 'loss' => A('izguba'), 'temp' => A('Temperatura'), 'conns' => A('Povezave'), 'tiger' => A('Ping s strežnika'), 'copied' => A('Kopirano'), 'copy' => A('Kopiraj'),
  'swNoChange' => A('Ni sprememb.'), 'swPreview' => A('Ukazi, ki jih izvede stikalo:'), 'swSend' => A('Pošlji na stikalo'), 'swSendAnyway' => A('Razumem, pošlji'),
  'swErr' => A('Napaka – osveži stran.'), 'swSafety' => A('Varovalka: stikalo pred spremembo shrani prejšnje stanje; če po spremembi ne doseže NOC, ga čez 5 minut samo povrne.')], JSON_UNESCAPED_UNICODE) ?> };</script>
<script src="/assets/vendor/uPlot.iife.min.js"></script>
<script src="/assets/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
