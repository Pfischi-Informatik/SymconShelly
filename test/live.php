<?php

declare(strict_types=1);

/**
 * Live-Test ohne Symcon. Stellt IPSModule und die IPS-Funktionen minimal nach.
 * Nach test/live.php kopieren, unten Modulpfad, Klasse und Pruefungen anpassen.
 *
 *   php -d zend.assertions=1 -d assert.exception=1 test/live.php [args]
 *   DEBUG=1 ... zeigt SendDebug-Ausgaben
 */

const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const KL_MESSAGE = 10206;
const KL_WARNING = 10;
const KL_ERROR = 20;
const KR_READY = 10103;
function IPS_VariableProfileExists($n) { global $profiles; return isset($profiles[$n]); }
function IPS_CreateVariableProfile($n, $t) { global $profiles; $profiles[$n] = []; }
function IPS_DeleteVariableProfile($n) { global $profiles; unset($profiles[$n]); }
function IPS_SetVariableProfileAssociation($n, $v, $name, $i, $c) { global $profiles; $profiles[$n][$v] = $name; }
function IPS_SetVariableProfileValues($n, $min, $max, $step) {}
function IPS_SetVariableProfileText($n, $prefix, $suffix) {}
function IPS_SetVariableProfileIcon($n, $icon) {}
function IPS_SetVariableProfileDigits($n, $d) {}
function IPS_GetInstance($i) { return ['ConnectionID' => $i === 12345 ? 7 : 0]; }
function Sys_GetNetworkInfo() { return [['IP' => '192.168.1.5']]; }
function IPS_GetKernelRunlevel() { return KR_READY; }

class IPSModule
{
    public $InstanceID = 12345;
    private $props = [], $attrs = [], $values = [];
    public $status = 0;
    protected function ConnectParent($g) {}
    protected function SetReceiveDataFilter($f) {}
    protected function GetIDForIdent($i) { return isset($this->values[$i]) ? 1 : 0; }
    public function Values() { return $this->values; }
    public function Create(): void {}
    public function Destroy(): void {}
    public function ApplyChanges(): void {}
    protected function RegisterPropertyString($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyInteger($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyBoolean($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyFloat($n, $v) { $this->props[$n] = $v; }
    public function SetProperty($n, $v) { $this->props[$n] = $v; }
    protected function ReadPropertyString($n) { return $this->props[$n]; }
    protected function ReadPropertyInteger($n) { return $this->props[$n]; }
    protected function ReadPropertyBoolean($n) { return $this->props[$n]; }
    protected function ReadPropertyFloat($n) { return $this->props[$n]; }
    protected function RegisterAttributeString($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    public function ReadAttributeString($n) { return $this->attrs[$n]; }
    public function ReadAttributeInteger($n) { return $this->attrs[$n]; }
    public function ReadAttributeBoolean($n) { return $this->attrs[$n]; }
    public function WriteAttributeString($n, $v) { $this->attrs[$n] = $v; }
    public function WriteAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    public function WriteAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterTimer($n, $i, $s) {}
    protected function SetTimerInterval($n, $i) {}
    protected function RegisterVariableBoolean($i, $n, $p = '', $pos = 0) { $this->values[$i] ??= false; }
    protected function RegisterVariableInteger($i, $n, $p = '', $pos = 0) { $this->values[$i] ??= 0; }
    protected function RegisterVariableFloat($i, $n, $p = '', $pos = 0) { $this->values[$i] ??= 0.0; }
    protected function RegisterVariableString($i, $n, $p = '', $pos = 0) { $this->values[$i] ??= ''; }
    protected function EnableAction($i) {}
    protected function SetValue($i, $v) { $this->values[$i] = $v; }
    public function GetValue($i) { return $this->values[$i]; }
    protected function SetStatus($s) { $this->status = $s; }
    protected function Translate($s) { return $s; }
    protected function LogMessage($m, $t) { fwrite(STDERR, "LOG: $m\n"); }
    protected function SendDebug($t, $m, $f) { if (getenv('DEBUG')) fwrite(STDERR, "DEBUG $t: " . substr((string) $m, 0, 400) . "\n"); }
}

require __DIR__ . '/../ShellyGeraet/module.php';

// Aufruf mit IP: echte Abfrage, nur lesen, MQTT bleibt unangetastet
if (isset($argv[1])) {
    $m = new ShellyGeraet();
    $m->Create();
    $m->SetProperty('Host', $argv[1]);
    $m->SetProperty('Password', $argv[2] ?? '');
    $m->SetProperty('ConfigureMQTT', false);
    $m->ApplyChanges();
    assert($m->Update(), 'Abfrage fehlgeschlagen');
    print_r($m->Values());
    exit;
}

class Fake extends ShellyGeraet
{
    public $calls = [];
    public $mqtt = ['enable' => false, 'server' => null, 'topic_prefix' => 'shelly-a', 'status_ntf' => false];
    protected function rpc(string $method, array $params = []): ?array
    {
        $this->calls[] = [$method, $params];
        switch ($method) {
            case 'Shelly.GetDeviceInfo': return ['id' => 'shelly-a'];
            case 'MQTT.GetConfig': return $this->mqtt;
            case 'MQTT.SetConfig': return ['restart_required' => true];
            case 'Shelly.GetStatus': return [
                'switch:0' => ['output' => true, 'apower' => 12.5, 'aenergy' => ['total' => 1500], 'temperature' => ['tC' => null]],
                'rgbw:0' => ['output' => true, 'brightness' => 40, 'rgb' => [255, 0, 16], 'white' => 7],
                'cover:1' => ['state' => 'stopped', 'current_pos' => 30],
                'em:0' => ['a_act_power' => 5.0, 'total_act_power' => 9.0],
                'wifi' => ['rssi' => -50],
            ];
        }
        return [];
    }
    public function cmd($c, $i, $f, $v) { return $this->command($c, $i, $f, $v); }
}

$m = new Fake();
$m->Create();
assert($m->status === 0);
$m->ApplyChanges();
assert($m->status === 104, 'ohne IP inaktiv');
$m->SetProperty('Host', '192.168.1.20');
$m->ApplyChanges();
assert($m->Update());
assert($m->status === 102);

// MQTT wird eingestellt und neu gestartet
$names = array_column($m->calls, 0);
assert(in_array('Shelly.Reboot', $names), 'Neustart');
$set = $m->calls[array_search('MQTT.SetConfig', $names)][1]['config'];
assert($set === ['enable' => true, 'server' => '192.168.1.5:1883', 'status_ntf' => true]);

// Schon richtig eingestellt: kein Neustart (sonst Neustart-Schleife)
$m->mqtt = array_merge($m->mqtt, $set);
$m->calls = [];
$m->ApplyChanges();
assert($m->Update());
assert(!in_array('Shelly.Reboot', array_column($m->calls, 0)), 'kein Neustart');

// Variablen
$v = $m->Values();
assert($v['switch_0_output'] === true);
assert($v['switch_0_aenergy_total'] === 1.5, 'Wh nach kWh');
assert(!isset($v['switch_0_temperature_tC']), 'null ueberspringen');
assert($v['rgbw_0_rgb'] === 0xFF0010);
assert($v['cover_1_current_pos'] === 30 && isset($v['cover_1_action']));
assert($v['em_0_a_act_power'] === 5.0);
assert($v['online'] === true);

// MQTT: nur eigener Topic, Teilmeldung aktualisiert
$rx = fn ($t, $p) => $m->ReceiveData(json_encode(['Topic' => $t, 'Payload' => $p]));
$rx('shelly-a/status/switch:0', '{"output":false}');
assert($m->Values()['switch_0_output'] === false);
$rx('shelly-b/status/switch:0', '{"output":true}');
assert($m->Values()['switch_0_output'] === false, 'fremdes Geraet');
$rx('shelly-a/online', 'false');
assert($m->Values()['online'] === false);

// Befehle
assert($m->cmd('switch', 0, 'output', true) === ['Switch.Set', ['id' => 0, 'on' => true]]);
assert($m->cmd('light', 1, 'brightness', 50) === ['Light.Set', ['id' => 1, 'on' => true, 'brightness' => 50]]);
assert($m->cmd('light', 1, 'brightness', 0) === ['Light.Set', ['id' => 1, 'on' => false]]);
assert($m->cmd('rgbw', 0, 'rgb', 0xFF0010) === ['RGBW.Set', ['id' => 0, 'on' => true, 'rgb' => [255, 0, 16]]]);
assert($m->cmd('rgbw', 0, 'white', 9) === ['RGBW.Set', ['id' => 0, 'on' => true, 'white' => 9]]);
assert($m->cmd('cover', 0, 'current_pos', 20) === ['Cover.GoToPosition', ['id' => 0, 'pos' => 20]]);
assert($m->cmd('cover', 0, 'action', 2) === ['Cover.Close', ['id' => 0]]);
// Konfigurator und Geraet muessen dieselbe Verbindung haben, sonst scheitert "Erstellen" in Symcon
$c = json_decode(file_get_contents(__DIR__ . '/../ShellyKonfigurator/module.json'), true);
$d = json_decode(file_get_contents(__DIR__ . '/../ShellyGeraet/module.json'), true);
assert($c['parentRequirements'] === $d['parentRequirements'] && $c['parentRequirements'] !== [], 'Verbindung');
assert($c['implemented'] === $d['implemented'], 'Datenfluss');
echo "OK\n";
