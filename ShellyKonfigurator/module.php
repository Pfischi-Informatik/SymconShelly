<?php

declare(strict_types=1);

/**
 * Findet Shellys ab Gen2 per mDNS (_shelly._tcp) und legt per Klick Instanzen an.
 * API: https://shelly-api-docs.shelly.cloud/gen2/General/mDNS
 */
class ShellyKonfigurator extends IPSModule
{
    private const DEVICE = '{93B8060F-DCD6-444D-8D7F-1ED9A0696C77}';
    private const DNSSD = '{780B2D48-916C-4D59-AD35-5A429B2355A5}';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('ManualHosts', '');
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $form['actions'][0]['values'] = $this->rows();
        return json_encode($form);
    }

    // ==== Liste ================================================================

    protected function rows(): array
    {
        $instances = [];
        foreach ($this->deviceInstances() as $id => $deviceId) {
            $instances[strtolower($deviceId)] = $id;
        }

        $rows = [];
        foreach ($this->discover() as $host) {
            $info = $this->deviceInfo($host);
            if ($info === null || !isset($info['id'])) {
                continue;
            }
            $key = strtolower($info['id']);
            $name = (string) ($info['name'] ?? $info['id']);
            $rows[$key] = [
                'name'       => $name,
                'model'      => (string) ($info['model'] ?? ''),
                'gen'        => (int) ($info['gen'] ?? 0),
                'host'       => $host,
                'device'     => (string) $info['id'],
                'instanceID' => $instances[$key] ?? 0,
                'create'     => [
                    'moduleID'      => self::DEVICE,
                    'name'          => $name,
                    'configuration' => ['Host' => $host, 'DeviceID' => (string) $info['id']],
                ],
            ];
        }

        // Angelegt, aber gerade nicht gefunden: sichtbar lassen, damit man sie loeschen kann
        foreach ($instances as $key => $id) {
            if (!isset($rows[$key])) {
                $rows[$key] = ['name' => IPS_GetName($id), 'model' => '', 'gen' => 0, 'host' => '', 'device' => $key, 'instanceID' => $id];
            }
        }
        return array_values($rows);
    }

    protected function deviceInstances(): array
    {
        $result = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE) as $id) {
            // Von Hand angelegte Instanzen haben keine ID und tauchen hier nicht auf
            $deviceId = IPS_GetProperty($id, 'DeviceID');
            if ($deviceId !== '') {
                $result[$id] = $deviceId;
            }
        }
        return $result;
    }

    protected function discover(): array
    {
        $hosts = array_filter(array_map('trim', explode(',', $this->ReadPropertyString('ManualHosts'))));
        $dnssd = IPS_GetInstanceListByModuleID(self::DNSSD);
        if ($dnssd !== []) {
            foreach (ZC_QueryServiceType($dnssd[0], '_shelly._tcp', '') as $service) {
                $details = ZC_QueryService($dnssd[0], $service['Name'], $service['Type'], $service['Domain']);
                foreach ((array) $details as $detail) {
                    if (!empty($detail['IPv4'][0])) {
                        $hosts[] = $detail['IPv4'][0];
                    }
                }
            }
        }
        return array_values(array_unique($hosts));
    }

    // GetDeviceInfo ist auch bei gesetztem Passwort offen
    protected function deviceInfo(string $host): ?array
    {
        $ch = curl_init('http://' . $host . '/rpc/Shelly.GetDeviceInfo');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ($body !== false && $code === 200) ? json_decode($body, true) : null;
    }
}
