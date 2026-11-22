<?php
loadFunctions(__DIR__ . '/../config.php', ['getKodeSNOMEDRadiologi']);

t('plain X-ray names map to their X-ray codes', function () {
    eq('168537006', getKodeSNOMEDRadiologi('Thorax PA'));
    eq('241645004', getKodeSNOMEDRadiologi('Foto Femur'));
});

t('CT names are NOT swallowed by generic X-ray keywords (regression)', function () {
    eq('303653007', getKodeSNOMEDRadiologi('CT Scan Kepala'));
    eq('418891003', getKodeSNOMEDRadiologi('CT Scan Thorax'));
    eq('303659006', getKodeSNOMEDRadiologi('CT Abdomen'));
});

t('USG names are NOT swallowed by "ABDOMEN" (regression)', function () {
    eq('241685008', getKodeSNOMEDRadiologi('USG Abdomen'));
    eq('268445003', getKodeSNOMEDRadiologi('USG Obstetri'));
});

t('matching is case-insensitive and trims whitespace', function () {
    eq('241055006', getKodeSNOMEDRadiologi('  mammografi  '));
});

t('unknown examinations fall back to the generic imaging code', function () {
    eq('363680008', getKodeSNOMEDRadiologi('Pemeriksaan Lain'));
});
