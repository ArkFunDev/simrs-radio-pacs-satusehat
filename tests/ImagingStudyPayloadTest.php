<?php
loadFunctions(__DIR__ . '/../imagingstudy.php', ['buildIS', 'extractAfterEq', 'likeEscape']);

function sampleStudy(array $override = []): array
{
    return array_merge([
        'modality'           => 'cr',
        'pacs_acc'           => '26111700010',
        'startedutc'         => '2026-11-17T03:00:00+00:00',
        'sr_id'              => 'sr-1',
        'study_instance_uid' => '1.2.3',
        'series_uid'         => '1.2.3',
        'instance_uid'       => '1.2.3',
        'sopclass'           => '1.2.840.10008.5.1.4.1.1.1',
        'patient_ihs'        => 'P123',
        'radihs'             => 'R9',
    ], $override);
}

t('buildIS produces the expected ImagingStudy structure', function () {
    $p = buildIS(sampleStudy(), 'ORG1');
    eq('ImagingStudy', $p['resourceType']);
    eq('available', $p['status']);
    eq('Patient/P123', $p['subject']['reference']);
    eq('2026-11-17T03:00:00+00:00', $p['started']);
    eq([['reference' => 'ServiceRequest/sr-1']], $p['basedOn']);
    eq('CR', $p['modality'][0]['code'], 'modality is upper-cased');
    eq('Practitioner/R9', $p['interpreter'][0]['reference']);
    eq('1.2.3', $p['series'][0]['uid']);
    eq('1.2.3', $p['series'][0]['instance'][0]['uid']);
    eq('urn:oid:1.2.840.10008.5.1.4.1.1.1', $p['series'][0]['instance'][0]['sopClass']['code']);
});

t('buildIS adds the ACSN identifier scoped to the organization', function () {
    $p = buildIS(sampleStudy(), 'ORG1');
    eq('http://sys-ids.kemkes.go.id/acsn/ORG1', $p['identifier'][0]['system']);
    eq('26111700010', $p['identifier'][0]['value']);
    eq('ACSN', $p['identifier'][0]['type']['coding'][0]['code']);
    eq('urn:dicom:uid', $p['identifier'][1]['system']);
    eq('urn:oid:1.2.3', $p['identifier'][1]['value']);
});

t('buildIS omits optional parts when data is missing', function () {
    $p = buildIS(sampleStudy(['sr_id' => '', 'pacs_acc' => '', 'radihs' => '']), 'ORG1');
    eq([], $p['basedOn']);
    eq(1, count($p['identifier']), 'only the study-UID identifier remains');
    eq(false, array_key_exists('interpreter', $p));
});

t('extractAfterEq pulls studyUID from a viewer URL', function () {
    eq('1.2.3', extractAfterEq('http://pacs.example/viewer.html?studyUID=1.2.3&x=1'));
    eq('1.2.3', extractAfterEq('http://pacs.example/viewer.html?studyUID=1.2.3'));
    eq('5', extractAfterEq('http://pacs.example/v?STUDYUID=5'), 'case-insensitive');
});

t('extractAfterEq falls back to the last "=" and handles empty input', function () {
    eq('9.9', extractAfterEq('a=b=9.9'));
    eq('plain', extractAfterEq('plain'));
    eq('', extractAfterEq(null));
    eq('', extractAfterEq(''));
});

t('likeEscape neutralises LIKE wildcards and backslashes', function () {
    eq('50\\%\\_a', likeEscape('50%_a'));
    eq('a\\\\b', likeEscape('a\\b'));
    eq('plain', likeEscape('plain'));
});
