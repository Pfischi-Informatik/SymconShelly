<?php

declare(strict_types=1);

/**
 * Ein Shelly ab Gen2. Variablen entstehen aus dem, was das Geraet meldet.
 * Status kommt per MQTT (status_ntf), Befehle und Abfragen laufen ueber HTTP-RPC.
 * API: https://shelly-api-docs.shelly.cloud/gen2/
 */
class ShellyGeraet extends IPSModule
{
    private const MQTT_SERVER = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';

    // Feld => [Name, Typ (0 bool, 1 int, 2 float, 3 string), Profil, Faktor, schaltbar]
    private const METER = [
        'apower'            => ['Leistung', 2, 'Shelly.Watt'],
        'voltage'           => ['Spannung', 2, 'Shelly.Volt'],
        'current'           => ['Strom', 2, 'Shelly.Ampere'],
        'freq'              => ['Frequenz', 2, 'Shelly.Hertz'],
        'aenergy.total'     => ['Energie', 2, 'Shelly.kWh', 0.001],
        'ret_aenergy.total' => ['Energie Rueckspeisung', 2, 'Shelly.kWh', 0.001],
        'temperature.tC'    => ['Temperatur', 2, '~Temperature'],
    ];
    private const OUTPUT = ['output' => ['Status', 0, '~Switch', 1, true]];
    private const DIM = ['brightness' => ['Helligkeit', 1, 'Shelly.Percent', 1, true]];
    private const COLOR = ['rgb' => ['Farbe', 1, '~HexColor', 1, true]];
    private const WHITE = ['white' => ['Weiss', 1, 'Shelly.Byte', 1, true]];

    // ==== Symcon ===============================================================

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('Poll', 60);
        $this->RegisterPropertyBoolean('ConfigureMQTT', true);
        $this->RegisterPropertyString('MQTTServer', '');
        $this->RegisterPropertyBoolean('InvertPosition', true);
        $this->RegisterAttributeString('Topic', '');
        $this->RegisterAttributeBoolean('Ready', false);
        $this->RegisterTimer('Update', 0, 'SHY_Update($_IPS[\'TARGET\']);');
        $this->ConnectParent(self::MQTT_SERVER);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->registerProfiles();
        $this->WriteAttributeBoolean('Ready', false);
        $this->applyFilter();

        if ($this->ReadPropertyString('Host') === '') {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(104);
            return;
        }
        // HTTP nicht hier: blockiert sonst den Start von Symcon
        $this->SetTimerInterval('Update', 1000);
    }

    public function ReceiveData($JSONString): string
    {
        $data = json_decode($JSONString, true);
        $topic = (string) ($data['Topic'] ?? '');
        $payload = mb_convert_encoding((string) ($data['Payload'] ?? ''), 'ISO-8859-1', 'UTF-8');
        $this->SendDebug('RX', $topic . ' = ' . $payload, 0);

        $base = $this->topic() . '/';
        if (!str_starts_with($topic, $base)) {
            return '';
        }
        $rest = substr($topic, strlen($base));
        if ($rest === 'online') {
            $this->setOnline($payload === 'true');
        } elseif (str_starts_with($rest, 'status/')) {
            $status = json_decode($payload, true);
            if (is_array($status)) {
                $this->applyComponent(substr($rest, 7), $status);
                $this->setOnline(true);
            }
        }
        return '';
    }

    public function RequestAction($Ident, $Value)
    {
        [$component, $id, $field] = explode('_', $Ident, 3);
        $id = (int) $id;
        [$method, $params] = $this->command($component, $id, $field, $Value);
        if ($this->rpc($method, $params) === null) {
            echo 'Shelly hat den Befehl nicht angenommen';
            return;
        }
        // Position meldet der Shelly erst waehrend der Fahrt selbst
        if ($field !== 'current_pos') {
            $this->SetValue($Ident, $Value);
        }
    }

    public function Update(): bool
    {
        $poll = $this->ReadPropertyInteger('Poll');
        $ready = $this->ReadAttributeBoolean('Ready') || $this->setup();
        $status = $ready ? $this->rpc('Shelly.GetStatus') : null;

        $this->SetTimerInterval('Update', ($ready ? $poll : max($poll, 60)) * 1000);
        if ($status === null) {
            if ($ready) {
                $this->SetStatus(201);
            }
            $this->setOnline(false);
            return false;
        }
        foreach ($status as $key => $value) {
            if (is_array($value)) {
                $this->applyComponent((string) $key, $value);
            }
        }
        $this->setOnline(true);
        $this->SetStatus(102);
        return true;
    }

    // ==== Befehle ==============================================================

    protected function command(string $component, int $id, string $field, $value): array
    {
        if ($component === 'cover') {
            if ($field === 'current_pos') {
                return ['Cover.GoToPosition', ['id' => $id, 'pos' => $this->position((int) $value)]];
            }
            return ['Cover.' . ['Open', 'Stop', 'Close'][(int) $value], ['id' => $id]];
        }
        $class = ['rgb' => 'RGB', 'rgbw' => 'RGBW', 'cct' => 'CCT'][$component] ?? ucfirst($component);
        $params = ['id' => $id];
        switch ($field) {
            case 'output':
                $params['on'] = (bool) $value;
                break;
            case 'brightness':
                $params['on'] = $value > 0;
                if ($value > 0) {
                    $params['brightness'] = (int) $value;
                }
                break;
            case 'rgb':
                $params += ['on' => true, 'rgb' => [($value >> 16) & 255, ($value >> 8) & 255, $value & 255]];
                break;
            default: // white, ct
                $params += ['on' => true, $field => (int) $value];
        }
        return [$class . '.Set', $params];
    }

    // ==== Variablen ============================================================

    private function fields(string $component): ?array
    {
        switch ($component) {
            case 'switch':
                return self::OUTPUT + self::METER;
            case 'light':
                return self::OUTPUT + self::DIM + self::METER;
            case 'cct':
                return self::OUTPUT + self::DIM + ['ct' => ['Farbtemperatur', 1, 'Shelly.Kelvin', 1, true]] + self::METER;
            case 'rgb':
                return self::OUTPUT + self::DIM + self::COLOR + self::METER;
            case 'rgbw':
                return self::OUTPUT + self::DIM + self::COLOR + self::WHITE + self::METER;
            case 'cover':
                return ['state' => ['Zustand', 3, ''], 'current_pos' => ['Position', 1, 'Shelly.Percent', 1, true]] + self::METER;
            case 'input':
                return ['state' => ['Eingang', 0, '~Switch'], 'percent' => ['Analogwert', 2, 'Shelly.PercentF']];
            case 'pm1':
                return self::METER;
            case 'em1':
                return ['act_power' => self::METER['apower']] + self::METER;
            case 'em':
                $fields = ['total_act_power' => ['Gesamtleistung', 2, 'Shelly.Watt'], 'total_current' => ['Gesamtstrom', 2, 'Shelly.Ampere']];
                foreach (['a', 'b', 'c'] as $phase) {
                    $label = 'L' . (ord($phase) - 96) . ' ';
                    $fields[$phase . '_act_power'] = [$label . 'Leistung', 2, 'Shelly.Watt'];
                    $fields[$phase . '_voltage'] = [$label . 'Spannung', 2, 'Shelly.Volt'];
                    $fields[$phase . '_current'] = [$label . 'Strom', 2, 'Shelly.Ampere'];
                }
                return $fields;
            case 'emdata':
                return ['total_act' => ['Energie', 2, 'Shelly.kWh', 0.001], 'total_act_ret' => ['Energie Rueckspeisung', 2, 'Shelly.kWh', 0.001]];
            case 'em1data':
                return ['total_act_energy' => ['Energie', 2, 'Shelly.kWh', 0.001], 'total_act_ret_energy' => ['Energie Rueckspeisung', 2, 'Shelly.kWh', 0.001]];
            case 'temperature':
                return ['tC' => ['Temperatur', 2, '~Temperature']];
            case 'humidity':
                return ['rh' => ['Luftfeuchte', 2, 'Shelly.PercentF']];
            case 'illuminance':
                return ['lux' => ['Helligkeit', 2, 'Shelly.Lux']];
            case 'voltmeter':
                return ['voltage' => ['Spannung', 2, 'Shelly.Volt']];
            case 'devicepower':
                return ['battery.percent' => ['Batterie', 2, 'Shelly.PercentF']];
        }
        return null;
    }

    protected function applyComponent(string $key, array $status): void
    {
        [$component, $id] = array_pad(explode(':', $key, 2), 2, '');
        $fields = $this->fields($component);
        if ($fields === null || !ctype_digit($id)) {
            return;
        }
        $id = (int) $id;
        $position = $id * 100 + 10;
        foreach ($fields as $path => $spec) {
            $position++;
            $value = $status;
            foreach (explode('.', $path) as $part) {
                $value = is_array($value) ? ($value[$part] ?? null) : null;
            }
            if ($value === null) {
                continue;
            }
            [$caption, $type, $profile] = $spec;
            $ident = $component . '_' . $id . '_' . str_replace('.', '_', $path);
            $this->ensure($ident, $this->label($component, $id) . $caption, $type, $profile, $position, $spec[4] ?? false);
            if ($path === 'rgb') {
                $value = ($value[0] << 16) | ($value[1] << 8) | $value[2];
            } elseif (is_numeric($value) && isset($spec[3])) {
                $value *= $spec[3];
            }
            if ($path === 'current_pos') {
                $value = $this->position((int) $value);
            }
            settype($value, ['boolean', 'integer', 'double', 'string'][$type]);
            $this->SetValue($ident, $value);
        }
        if ($component === 'cover') {
            $this->ensure('cover_' . $id . '_action', $this->label($component, $id) . 'Steuerung', 1, 'Shelly.Cover', $id * 100 + 9, true);
        }
    }

    // Shelly: 100 = offen. Mit Umkehrung in Symcon: 0 = offen. Gilt in beide Richtungen.
    private function position(int $value): int
    {
        return $this->ReadPropertyBoolean('InvertPosition') ? 100 - $value : $value;
    }

    private function label(string $component, int $id): string
    {
        $names = ['switch' => 'Schalter', 'light' => 'Licht', 'cover' => 'Rollladen', 'input' => 'Eingang', 'temperature' => 'Sensor', 'humidity' => 'Sensor', 'illuminance' => 'Sensor', 'voltmeter' => 'Sensor', 'devicepower' => 'Geraet', 'em' => 'Zaehler', 'em1' => 'Zaehler', 'emdata' => 'Zaehler', 'em1data' => 'Zaehler', 'pm1' => 'Zaehler'];
        return ($names[$component] ?? strtoupper($component)) . ' ' . ($id + 1) . ' ';
    }

    private function ensure(string $ident, string $name, int $type, string $profile, int $position, bool $action): void
    {
        if (@$this->GetIDForIdent($ident)) {
            return;
        }
        $register = ['RegisterVariableBoolean', 'RegisterVariableInteger', 'RegisterVariableFloat', 'RegisterVariableString'][$type];
        $this->$register($ident, $name, $profile, $position);
        if ($action) {
            $this->EnableAction($ident);
        }
    }

    private function setOnline(bool $online): void
    {
        $this->ensure('online', 'Online', 0, '~Switch', 0, false);
        $this->SetValue('online', $online);
    }

    private function registerProfiles(): void
    {
        $float = ['Watt' => ' W', 'Volt' => ' V', 'Ampere' => ' A', 'Hertz' => ' Hz', 'kWh' => ' kWh', 'PercentF' => ' %', 'Lux' => ' lx'];
        foreach ($float as $name => $suffix) {
            if (!IPS_VariableProfileExists('Shelly.' . $name)) {
                IPS_CreateVariableProfile('Shelly.' . $name, VARIABLETYPE_FLOAT);
                IPS_SetVariableProfileDigits('Shelly.' . $name, $name === 'kWh' ? 3 : 1);
                IPS_SetVariableProfileText('Shelly.' . $name, '', $suffix);
            }
        }
        foreach (['Percent' => [0, 100, 1, ' %'], 'Byte' => [0, 255, 1, ''], 'Kelvin' => [2700, 6500, 100, ' K']] as $name => $v) {
            if (!IPS_VariableProfileExists('Shelly.' . $name)) {
                IPS_CreateVariableProfile('Shelly.' . $name, VARIABLETYPE_INTEGER);
                IPS_SetVariableProfileValues('Shelly.' . $name, $v[0], $v[1], $v[2]);
                IPS_SetVariableProfileText('Shelly.' . $name, '', $v[3]);
            }
        }
        if (!IPS_VariableProfileExists('Shelly.Cover')) {
            IPS_CreateVariableProfile('Shelly.Cover', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('Shelly.Cover', 0, 'Auf', '', -1);
            IPS_SetVariableProfileAssociation('Shelly.Cover', 1, 'Stopp', '', -1);
            IPS_SetVariableProfileAssociation('Shelly.Cover', 2, 'Zu', '', -1);
        }
    }

    // ==== MQTT =================================================================

    private function topic(): string
    {
        return $this->ReadAttributeString('Topic') ?: $this->ReadPropertyString('DeviceID');
    }

    private function applyFilter(): void
    {
        $topic = $this->topic();
        $this->SetReceiveDataFilter($topic === '' ? '.*NICHTS.*' : '.*' . preg_quote($topic) . '.*');
    }

    protected function setup(): bool
    {
        $info = $this->rpc('Shelly.GetDeviceInfo');
        $config = $info === null ? null : $this->rpc('MQTT.GetConfig');
        if ($config === null) {
            $this->SetStatus(201);
            return false;
        }
        $this->WriteAttributeString('Topic', (string) (($config['topic_prefix'] ?? '') ?: $info['id']));
        $this->applyFilter();

        if ($this->ReadPropertyBoolean('ConfigureMQTT')) {
            $server = $this->mqttServer();
            if ($server === '') {
                $this->SetStatus(202);
                return false;
            }
            $wanted = ['enable' => true, 'server' => $server, 'status_ntf' => true];
            $changes = array_filter($wanted, fn ($v, $k) => ($config[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);
            if ($changes !== []) {
                if (!empty($config['enable']) && ($config['server'] ?? '') !== $server) {
                    $this->LogMessage('MQTT-Server ersetzt, vorher: ' . $config['server'], KL_WARNING);
                }
                $result = $this->rpc('MQTT.SetConfig', ['config' => $changes]);
                if ($result === null) {
                    $this->SetStatus(201);
                    return false;
                }
                if (!empty($result['restart_required'])) {
                    $this->rpc('Shelly.Reboot');
                }
            }
        }
        $this->WriteAttributeBoolean('Ready', true);
        return true;
    }

    private function mqttServer(): string
    {
        $ip = $this->ReadPropertyString('MQTTServer');
        if ($ip === '') {
            // ponytail: /24-Vergleich statt echter Netzmaske, sonst MQTTServer von Hand setzen
            $net = implode('.', array_slice(explode('.', gethostbyname($this->ReadPropertyString('Host'))), 0, 3)) . '.';
            foreach (Sys_GetNetworkInfo() as $interface) {
                if (str_starts_with((string) ($interface['IP'] ?? ''), $net)) {
                    $ip = $interface['IP'];
                    break;
                }
            }
        }
        if ($ip === '') {
            return '';
        }
        $splitter = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $socket = $splitter > 0 ? IPS_GetInstance($splitter)['ConnectionID'] : 0;
        $port = $socket > 0 ? (int) @IPS_GetProperty($socket, 'Port') : 0;
        return $ip . ':' . ($port ?: 1883);
    }

    // ==== HTTP-RPC =============================================================

    /** @return array|null null = Fehler; [] = Erfolg ohne Rueckgabe (z.B. Light.Set) */
    protected function rpc(string $method, array $params = []): ?array
    {
        $ch = curl_init('http://' . $this->ReadPropertyString('Host') . '/rpc/' . $method);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $params === [] ? '{}' : json_encode($params),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ];
        $password = $this->ReadPropertyString('Password');
        if ($password !== '') {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_DIGEST;
            $options[CURLOPT_USERPWD] = 'admin:' . $password;
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->SendDebug('RPC', $method . ' -> ' . $code, 0);
        if ($body === false || $code !== 200) {
            return null;
        }
        return json_decode($body, true) ?? [];
    }
}
