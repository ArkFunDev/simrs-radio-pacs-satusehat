<?php
loadFunctions(__DIR__ . '/../config.php', ['accessionBase', 'accessionFor', 'formatTanggalIndo', 'e']);

t('accessionBase drops "PR" and the century digits', function () {
    eq('2611170001', accessionBase('PR202611170001'));
});

t('accessionFor appends the 0-based examination index', function () {
    eq('26111700010', accessionFor('PR202611170001', 0));
    eq('26111700012', accessionFor('PR202611170001', 2));
});

t('formatTanggalIndo formats an ISO date', function () {
    eq('17 November 2026', formatTanggalIndo('2026-11-17'));
    eq('01 Januari 2026', formatTanggalIndo('2026-01-01'));
});

t('formatTanggalIndo returns "-" for empty / zero dates', function () {
    eq('-', formatTanggalIndo(null));
    eq('-', formatTanggalIndo(''));
    eq('-', formatTanggalIndo('0000-00-00'));
});

t('e() escapes HTML including quotes', function () {
    eq('&lt;b&gt;&quot;x&quot; &amp; &#039;y&#039;', e('<b>"x" & \'y\''));
});
