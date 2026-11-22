<?php
loadFunctions(__DIR__ . '/../config.php', ['extractPacsOrderData']);

t('extractPacsOrderData reads the nested PACS response', function () {
    $r = extractPacsOrderData([
        'uid'            => '1.2.3',
        'acc'            => '11170001',
        'prosedur'       => 'Thorax PA',
        'xray_type_code' => 'CR',
        'dokradid'       => 'D1',
        'dokrad_name'    => 'dr. Radiolog',
        'named'          => 'dr. Perujuk',
        'study'          => ['study_iuid' => '9.9', 'num_instances' => 3, 'mods_in_study' => 'DX'],
        'workload'       => ['status' => 'approved', 'fill' => 'Hasil baik', 'approved_at' => '2026-11-17 10:00:00'],
    ]);
    eq('1.2.3', $r['study_uid'], 'data.uid wins over study.study_iuid');
    eq('11170001', $r['acc_confirmed']);
    eq('CR', $r['modality'], 'xray_type_code wins over mods_in_study');
    eq(3, $r['image_count']);
    eq('approved', $r['report_status']);
    eq('dr. Radiolog', $r['dokrad_name']);
    eq('dr. Perujuk', $r['referring_doctor']);
    eq(true, $r['has_image']);
    eq(true, $r['has_report']);
});

t('falls back to study.* fields when top-level ones are missing', function () {
    $r = extractPacsOrderData(['study' => ['study_iuid' => '9.9.9', 'img' => '5', 'mods_in_study' => 'CT']]);
    eq('9.9.9', $r['study_uid']);
    eq(5, $r['image_count']);
    eq('CT', $r['modality']);
    eq(true, $r['has_image']);
    eq(false, $r['has_report']);
});

t('an empty response yields an empty, safe result', function () {
    $r = extractPacsOrderData([]);
    eq('', $r['study_uid']);
    eq(0, $r['image_count']);
    eq('', $r['report_status']);
    eq(false, $r['has_image']);
    eq(false, $r['has_report']);
    eq('', $r['expertise_text']);
});

t('HTML entities in the report are decoded', function () {
    $r = extractPacsOrderData(['workload' => ['fill' => 'A &amp; B &gt; C']]);
    eq('A & B > C', $r['expertise_text']);
});

t('stray word "view" is removed but "Review"/"Overview" are kept', function () {
    $r = extractPacsOrderData(['workload' => ['fill' => 'Review view ulang. Overview baik.']]);
    eq('Review ulang. Overview baik.', $r['expertise_text']);
});

t('plain-text version turns <br> into newlines', function () {
    $r = extractPacsOrderData(['workload' => ['fill' => 'Hasil baik<br>Tidak ada kelainan']]);
    eq('Hasil baik<br>Tidak ada kelainan', $r['expertise_text']);
    eq("Hasil baik\nTidak ada kelainan", $r['expertise_text_plain']);
});
