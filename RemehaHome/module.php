<?php

declare(strict_types=1);

/**
 * Remeha Home (eTwist) für IP-Symcon
 *
 * Portierung der Home-Assistant-Integration msvisser/remeha_home.
 * Kommuniziert über die Cloud-API der Remeha-Home-App (BDR Thermea).
 */
class RemehaHome extends IPSModule
{
    private const API_BASE = 'https://api.bdrthermea.net/Mobile/api';
    private const SUBSCRIPTION_KEY = 'df605c5470d846fc91e848b1cc653ddf';

    private const AUTH_BASE = 'https://remehalogin.bdrthermea.net/bdrb2cprod.onmicrosoft.com';
    private const POLICY = 'B2C_1A_RPSignUpSignInNewRoomV3.1';
    private const POLICY_PATH = 'B2C_1A_RPSignUpSignInNewRoomv3.1';
    private const CLIENT_ID = '6ce007c6-0628-419e-88f4-bee2e6418eec';
    private const REDIRECT_URI = 'com.b2c.remehaapp://login-callback';
    private const SCOPE = 'openid https://bdrb2cprod.onmicrosoft.com/iotdevice/user_impersonation offline_access';
    private const USER_AGENT = 'Mozilla/5.0 (IP-Symcon RemehaHome)';

    private const ENERGY_INTERVAL = 885; // 14:45 min wie in der HA-Integration

    // Zonenmodus (Variable ZoneMode)
    private const MODE_SCHEDULE = 0;
    private const MODE_MANUAL = 1;
    private const MODE_OFF = 2;
    private const MODE_TEMPORARY = 3;

    // Warmwassermodus (Variable DHWMode)
    private const DHW_SCHEDULE = 0;
    private const DHW_COMFORT = 1;
    private const DHW_ECO = 2;

    private const STATUS_LOGIN_FAILED = 201;
    private const STATUS_CONNECTION_ERROR = 202;
    private const STATUS_NO_ZONE = 203;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterPropertyString('ClimateZoneId', '');
        $this->RegisterPropertyBoolean('EnableHotWater', true);
        $this->RegisterPropertyBoolean('EnableEnergy', true);

        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('TokenExpires', 0);
        $this->RegisterAttributeString('TokenOwner', '');
        $this->RegisterAttributeString('ApplianceId', '');
        $this->RegisterAttributeString('ActiveClimateZoneId', '');
        $this->RegisterAttributeString('HotWaterZoneId', '');
        $this->RegisterAttributeInteger('LastEnergyUpdate', 0);

        $this->RegisterTimer('UpdateTimer', 0, 'RMH_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('RefreshTimer', 0, 'RMH_Update($_IPS[\'TARGET\']);');

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();
        $this->MaintainVariables();

        $email = trim($this->ReadPropertyString('Email'));
        if ($this->ReadAttributeString('TokenOwner') !== $email) {
            $this->ClearTokens();
            $this->WriteAttributeString('TokenOwner', $email);
        }
        $this->WriteAttributeInteger('LastEnergyUpdate', 0);

        if ($email === '' || $this->ReadPropertyString('Password') === '') {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        $this->SetTimerInterval('UpdateTimer', max(30, $this->ReadPropertyInteger('UpdateInterval')) * 1000);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Update();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'SetPoint':
                $this->SetTemperature((float) $Value);
                break;
            case 'ZoneMode':
                $this->SetMode((int) $Value);
                break;
            case 'TimeProgram':
                $this->SetTimeProgram((int) $Value);
                break;
            case 'FireplaceMode':
                $this->SetFireplaceMode((bool) $Value);
                break;
            case 'DHWMode':
                $this->SetHotWaterMode((int) $Value);
                break;
            case 'DHWComfortSetpoint':
                $this->SetHotWaterComfortSetpoint((float) $Value);
                break;
            case 'DHWReducedSetpoint':
                $this->SetHotWaterReducedSetpoint((float) $Value);
                break;
            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    // ---------------------------------------------------------------------
    // Öffentliche Funktionen (RMH_*)
    // ---------------------------------------------------------------------

    public function Update(): bool
    {
        $this->SetTimerInterval('RefreshTimer', 0);

        if (trim($this->ReadPropertyString('Email')) === '' || $this->ReadPropertyString('Password') === '') {
            return false;
        }

        try {
            $dashboard = $this->ApiRequest('GET', '/homes/dashboard?t=' . time());
            $this->SendDebug('Dashboard', json_encode($dashboard), 0);

            [$appliance, $zone, $hotWater] = $this->SelectZones($dashboard);
            if ($zone === null) {
                $this->SetStatus(self::STATUS_NO_ZONE);
                return false;
            }

            $this->WriteAttributeString('ApplianceId', $appliance['applianceId']);
            $this->WriteAttributeString('ActiveClimateZoneId', $zone['climateZoneId']);
            $this->WriteAttributeString('HotWaterZoneId', $hotWater['hotWaterZoneId'] ?? '');

            $this->UpdateAppliance($appliance);
            $this->UpdateClimateZone($zone);
            if ($this->ReadPropertyBoolean('EnableHotWater') && $hotWater !== null) {
                $this->UpdateHotWaterZone($hotWater);
            }
            if ($this->ReadPropertyBoolean('EnableEnergy')) {
                $this->UpdateEnergy($appliance['applianceId']);
            }

            $this->SetStatus(IS_ACTIVE);
            return true;
        } catch (Exception $e) {
            $this->HandleError($e);
            return false;
        }
    }

    /**
     * Setzt die Solltemperatur. Im Zeitprogramm wird eine temporäre Übersteuerung
     * gesetzt, im manuellen Modus der manuelle Sollwert (wie climate.py).
     */
    public function SetTemperature(float $Temperature): bool
    {
        $mode = $this->GetValue('ZoneMode');
        switch ($mode) {
            case self::MODE_SCHEDULE:
            case self::MODE_TEMPORARY:
                $path = '/modes/temporary-override';
                break;
            case self::MODE_MANUAL:
                $path = '/modes/manual';
                break;
            default:
                $this->LogMessage($this->Translate('The zone is switched off, the setpoint cannot be changed.'), KL_WARNING);
                return false;
        }

        $ok = $this->ClimateZoneCommand($path, ['roomTemperatureSetPoint' => $Temperature]);
        if ($ok) {
            $this->SetValue('SetPoint', $Temperature);
            if ($mode === self::MODE_SCHEDULE) {
                $this->SetValue('ZoneMode', self::MODE_TEMPORARY);
            }
        }
        return $ok;
    }

    /**
     * 0 = Zeitprogramm, 1 = Manuell, 2 = Aus (Frostschutz), 3 = Temporäre Übersteuerung
     */
    public function SetMode(int $Mode): bool
    {
        switch ($Mode) {
            case self::MODE_SCHEDULE:
                $ok = $this->ClimateZoneCommand('/modes/schedule', ['heatingProgramId' => $this->GetValue('TimeProgram')]);
                break;
            case self::MODE_MANUAL:
                $ok = $this->ClimateZoneCommand('/modes/manual', ['roomTemperatureSetPoint' => $this->GetValue('SetPoint')]);
                break;
            case self::MODE_OFF:
                $ok = $this->ClimateZoneCommand('/modes/anti-frost');
                break;
            case self::MODE_TEMPORARY:
                $ok = $this->ClimateZoneCommand('/modes/temporary-override', ['roomTemperatureSetPoint' => $this->GetValue('SetPoint')]);
                break;
            default:
                throw new Exception('Invalid mode: ' . $Mode);
        }

        if ($ok) {
            $this->SetValue('ZoneMode', $Mode);
        }
        return $ok;
    }

    /**
     * Aktiviert Zeitprogramm 1–3 und schaltet bei Bedarf in den Zeitprogramm-Modus.
     */
    public function SetTimeProgram(int $Program): bool
    {
        if ($Program < 1 || $Program > 3) {
            throw new Exception('Invalid time program: ' . $Program);
        }

        $previousMode = $this->GetValue('ZoneMode');
        $ok = $this->ClimateZoneCommand('/time-programs/heating/' . $Program . '/activate');
        if ($ok && $previousMode !== self::MODE_SCHEDULE && $previousMode !== self::MODE_TEMPORARY) {
            $ok = $this->ClimateZoneCommand('/modes/schedule', ['heatingProgramId' => $Program]);
        }

        if ($ok) {
            $this->SetValue('TimeProgram', $Program);
            $this->SetValue('ZoneMode', self::MODE_SCHEDULE);
        }
        return $ok;
    }

    public function SetFireplaceMode(bool $Active): bool
    {
        $ok = $this->ClimateZoneCommand('/modes/fireplacemode', ['fireplaceModeActive' => $Active]);
        if ($ok) {
            $this->SetValue('FireplaceMode', $Active);
        }
        return $ok;
    }

    /**
     * 0 = Zeitprogramm, 1 = Komfort, 2 = Eco
     */
    public function SetHotWaterMode(int $Mode): bool
    {
        $paths = [
            self::DHW_SCHEDULE => '/modes/schedule',
            self::DHW_COMFORT  => '/modes/continuous-comfort',
            self::DHW_ECO      => '/modes/anti-frost',
        ];
        if (!isset($paths[$Mode])) {
            throw new Exception('Invalid hot water mode: ' . $Mode);
        }

        $ok = $this->HotWaterZoneCommand($paths[$Mode]);
        if ($ok) {
            $this->SetValue('DHWMode', $Mode);
        }
        return $ok;
    }

    public function SetHotWaterComfortSetpoint(float $Temperature): bool
    {
        $ok = $this->HotWaterZoneCommand('/comfort-setpoint', ['comfortSetpoint' => $Temperature]);
        if ($ok) {
            $this->SetValue('DHWComfortSetpoint', $Temperature);
        }
        return $ok;
    }

    public function SetHotWaterReducedSetpoint(float $Temperature): bool
    {
        $ok = $this->HotWaterZoneCommand('/reduced-setpoint', ['reducedSetpoint' => $Temperature]);
        if ($ok) {
            $this->SetValue('DHWReducedSetpoint', $Temperature);
        }
        return $ok;
    }

    /**
     * Listet alle Geräte und Zonen des Kontos (für die Auswahl der Klimazone).
     */
    public function ListZones(): string
    {
        try {
            $dashboard = $this->ApiRequest('GET', '/homes/dashboard?t=' . time());
        } catch (Exception $e) {
            $this->HandleError($e);
            return $e->getMessage();
        }

        $lines = [];
        foreach ($dashboard['appliances'] ?? [] as $appliance) {
            $lines[] = sprintf('%s (%s)', $appliance['houseName'] ?? '?', $appliance['applianceId']);
            foreach ($appliance['climateZones'] ?? [] as $zone) {
                $lines[] = sprintf('  %s: %s', $zone['name'] ?? '?', $zone['climateZoneId']);
            }
            foreach ($appliance['hotWaterZones'] ?? [] as $zone) {
                $lines[] = sprintf('  %s (DHW): %s', $zone['name'] ?? '?', $zone['hotWaterZoneId']);
            }
        }
        return $lines === [] ? $this->Translate('No appliances found') : implode("\n", $lines);
    }

    /**
     * Verwirft die gespeicherten Tokens; beim nächsten Abruf erfolgt ein neuer Login.
     */
    public function ResetLogin(): void
    {
        $this->ClearTokens();
        $this->Update();
    }

    // ---------------------------------------------------------------------
    // Datenverarbeitung
    // ---------------------------------------------------------------------

    private function SelectZones(array $dashboard): array
    {
        $wanted = trim($this->ReadPropertyString('ClimateZoneId'));

        foreach ($dashboard['appliances'] ?? [] as $appliance) {
            foreach ($appliance['climateZones'] ?? [] as $zone) {
                if ($wanted === '' || strcasecmp($zone['climateZoneId'], $wanted) === 0) {
                    return [$appliance, $zone, $appliance['hotWaterZones'][0] ?? null];
                }
            }
        }

        $this->SendDebug('SelectZones', 'No matching climate zone found', 0);
        return [null, null, null];
    }

    private function UpdateAppliance(array $appliance): void
    {
        $this->SetVar('Online', $appliance['applianceOnline'] ?? null);
        $this->SetVar('WaterPressure', $appliance['waterPressure'] ?? null);
        $this->SetVar('ErrorStatus', $appliance['errorStatus'] ?? null);
        $this->SetVar('ThermalMode', $appliance['activeThermalMode'] ?? null);
        $this->SetVar('OutdoorTemperature', $appliance['outdoorTemperatureInformation']['applianceOutdoorTemperature'] ?? null);
        $this->SetVar('CloudOutdoorTemperature', $appliance['outdoorTemperatureInformation']['cloudOutdoorTemperature'] ?? null);
    }

    private function UpdateClimateZone(array $zone): void
    {
        $modes = [
            'Scheduling'        => self::MODE_SCHEDULE,
            'TemporaryOverride' => self::MODE_TEMPORARY,
            'Manual'            => self::MODE_MANUAL,
            'FrostProtection'   => self::MODE_OFF,
        ];
        $zoneMode = $zone['zoneMode'] ?? '';
        if (isset($modes[$zoneMode])) {
            $this->SetVar('ZoneMode', $modes[$zoneMode]);
        } else {
            $this->SendDebug('ZoneMode', 'Unknown zone mode: ' . $zoneMode, 0);
        }

        $this->SetVar('RoomTemperature', $zone['roomTemperature'] ?? null);
        $this->SetVar('SetPoint', $zone['setPoint'] ?? null);
        $this->SetVar('CurrentScheduleSetPoint', $zone['currentScheduleSetPoint'] ?? null);
        $this->SetVar('NextSetpoint', $zone['nextSetpoint'] ?? null);
        $this->SetVar('NextSwitchTime', $this->ParseTime($zone['nextSwitchTime'] ?? null));
        $this->SetVar('TimeProgram', $zone['activeHeatingClimateTimeProgramNumber'] ?? null);
        $this->SetVar('FireplaceMode', $zone['firePlaceModeActive'] ?? null);
        $this->SetVar('HeatingActive', in_array($zone['activeComfortDemand'] ?? '', ['ProducingHeat', 'RequestingHeat'], true));
    }

    private function UpdateHotWaterZone(array $zone): void
    {
        $mode = $zone['dhwZoneMode'] ?? '';
        switch ($mode) {
            case 'Scheduling':
            case 'Schedule':
                $this->SetVar('DHWMode', self::DHW_SCHEDULE);
                break;
            case 'ContinuousComfort':
            case 'Comfort':
                $this->SetVar('DHWMode', self::DHW_COMFORT);
                break;
            default:
                // "Off", "AntiFrost" usw. entsprechen dem Eco-Modus der App
                $this->SetVar('DHWMode', self::DHW_ECO);
                $this->SendDebug('DHWMode', 'Mapped zone mode "' . $mode . '" to Eco', 0);
        }

        $this->SetVar('DHWTemperature', $zone['dhwTemperature'] ?? null);
        $this->SetVar('DHWTargetSetpoint', $zone['targetSetpoint'] ?? null);
        $this->SetVar('DHWComfortSetpoint', $zone['comfortSetPoint'] ?? null);
        $this->SetVar('DHWReducedSetpoint', $zone['reducedSetpoint'] ?? null);
        $this->SetVar('DHWActive', ($zone['dhwStatus'] ?? '') === 'ProducingHeat');
    }

    private function UpdateEnergy(string $applianceId): void
    {
        if (time() - $this->ReadAttributeInteger('LastEnergyUpdate') < self::ENERGY_INTERVAL) {
            return;
        }

        $start = date('Y-m-d') . ' 00:00:00.000000Z';
        $end = date('Y-m-d') . ' 23:59:59.000000Z';
        $path = sprintf(
            '/appliances/%s/energyconsumption/daily?startDate=%s&endDate=%s',
            rawurlencode($applianceId),
            rawurlencode($start),
            rawurlencode($end)
        );

        try {
            $data = $this->ApiRequest('GET', $path);
        } catch (Exception $e) {
            // Verbrauchsdaten sind optional – Fehler nur protokollieren (wie coordinator.py)
            $this->SendDebug('Energy', 'Failed: ' . $e->getMessage(), 0);
            return;
        }
        $this->SendDebug('Energy', json_encode($data), 0);

        $today = $data['data'][0] ?? [];
        $this->SetVar('HeatingEnergyConsumed', (float) ($today['heatingEnergyConsumed'] ?? 0));
        $this->SetVar('HotWaterEnergyConsumed', (float) ($today['hotWaterEnergyConsumed'] ?? 0));
        $this->SetVar('HeatingEnergyDelivered', (float) ($today['heatingEnergyDelivered'] ?? 0));
        $this->SetVar('HotWaterEnergyDelivered', (float) ($today['hotWaterEnergyDelivered'] ?? 0));

        $this->WriteAttributeInteger('LastEnergyUpdate', time());
    }

    private function ClimateZoneCommand(string $path, ?array $body = null): bool
    {
        $zoneId = $this->ReadAttributeString('ActiveClimateZoneId');
        if ($zoneId === '' && $this->Update()) {
            $zoneId = $this->ReadAttributeString('ActiveClimateZoneId');
        }
        if ($zoneId === '') {
            $this->LogMessage($this->Translate('No climate zone known yet.'), KL_ERROR);
            return false;
        }
        return $this->Command('/climate-zones/' . rawurlencode($zoneId) . $path, $body);
    }

    private function HotWaterZoneCommand(string $path, ?array $body = null): bool
    {
        $zoneId = $this->ReadAttributeString('HotWaterZoneId');
        if ($zoneId === '') {
            $this->LogMessage($this->Translate('No hot water zone available.'), KL_ERROR);
            return false;
        }
        return $this->Command('/hot-water-zones/' . rawurlencode($zoneId) . $path, $body);
    }

    private function Command(string $path, ?array $body): bool
    {
        try {
            $this->ApiRequest('POST', $path, $body);
        } catch (Exception $e) {
            $this->HandleError($e);
            return false;
        }

        // Die Cloud übernimmt Änderungen leicht verzögert – kurz danach neu abfragen
        $this->SetTimerInterval('RefreshTimer', 5000);
        return true;
    }

    // ---------------------------------------------------------------------
    // API
    // ---------------------------------------------------------------------

    private function ApiRequest(string $method, string $path, ?array $body = null, bool $retry = true)
    {
        $token = $this->GetAccessToken();

        $headers = [
            'Authorization: Bearer ' . $token,
            'Ocp-Apim-Subscription-Key: ' . self::SUBSCRIPTION_KEY,
            'Accept: application/json',
        ];

        $ch = curl_init(self::API_BASE . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, '');
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $this->SendDebug('API', $method . ' ' . $path . ($body !== null ? ' ' . json_encode($body) : ''), 0);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('HTTP error: ' . $error, self::STATUS_CONNECTION_ERROR);
        }
        if ($code === 401 && $retry) {
            // Token abgelaufen/ungültig: einmal erneuern und wiederholen
            $this->WriteAttributeString('AccessToken', '');
            $this->WriteAttributeInteger('TokenExpires', 0);
            return $this->ApiRequest($method, $path, $body, false);
        }
        if ($code >= 400) {
            throw new Exception(sprintf('API %s %s returned HTTP %d: %s', $method, $path, $code, $response), self::STATUS_CONNECTION_ERROR);
        }
        if ($response === '') {
            return null;
        }
        return json_decode($response, true);
    }

    private function GetAccessToken(): string
    {
        $token = $this->ReadAttributeString('AccessToken');
        if ($token !== '' && time() < $this->ReadAttributeInteger('TokenExpires')) {
            return $token;
        }

        // Verhindert parallele Logins durch Timer und Aktionen
        if (!IPS_SemaphoreEnter('RMH_Auth_' . $this->InstanceID, 60000)) {
            throw new Exception('Timeout waiting for authentication lock', self::STATUS_CONNECTION_ERROR);
        }
        try {
            $token = $this->ReadAttributeString('AccessToken');
            if ($token !== '' && time() < $this->ReadAttributeInteger('TokenExpires')) {
                return $token;
            }

            $result = null;
            $refreshToken = $this->ReadAttributeString('RefreshToken');
            if ($refreshToken !== '') {
                try {
                    $result = $this->RequestToken([
                        'grant_type'    => 'refresh_token',
                        'refresh_token' => $refreshToken,
                        'client_id'     => self::CLIENT_ID,
                    ]);
                    $this->SendDebug('Auth', 'Token refreshed', 0);
                } catch (Exception $e) {
                    $this->SendDebug('Auth', 'Refresh failed, logging in again: ' . $e->getMessage(), 0);
                }
            }
            if ($result === null) {
                // Anders als in HA liegt das Passwort vor, daher ist ein stiller Re-Login möglich
                $result = $this->Login();
            }

            $this->WriteAttributeString('AccessToken', $result['access_token']);
            if (!empty($result['refresh_token'])) {
                $this->WriteAttributeString('RefreshToken', $result['refresh_token']);
            }
            $expiresIn = (int) ($result['expires_in'] ?? 3600);
            $this->WriteAttributeInteger('TokenExpires', time() + max(60, $expiresIn - 120));

            return $result['access_token'];
        } finally {
            IPS_SemaphoreLeave('RMH_Auth_' . $this->InstanceID);
        }
    }

    /**
     * Login über Azure AD B2C mit PKCE (entspricht async_resolve_external_data in api.py).
     */
    private function Login(): array
    {
        $email = trim($this->ReadPropertyString('Email'));
        $password = $this->ReadPropertyString('Password');
        $this->SendDebug('Auth', 'Logging in as ' . $email, 0);

        $state = $this->Base64Url(random_bytes(32));
        $codeVerifier = $this->Base64Url(random_bytes(48));
        $codeChallenge = $this->Base64Url(hash('sha256', $codeVerifier, true));

        // Ein Handle für alle Schritte, damit Cookies (CSRF) erhalten bleiben
        $ch = curl_init();
        try {
            // 1. Login-Seite anfordern
            $query = http_build_query([
                'response_type'         => 'code',
                'client_id'             => self::CLIENT_ID,
                'redirect_uri'          => self::REDIRECT_URI,
                'scope'                 => self::SCOPE,
                'state'                 => $state,
                'code_challenge'        => $codeChallenge,
                'code_challenge_method' => 'S256',
                'p'                     => self::POLICY,
                'brand'                 => 'remeha',
                'lang'                  => 'en',
                'nonce'                 => 'defaultNonce',
                'prompt'                => 'login',
                'signUp'                => 'False',
            ], '', '&', PHP_QUERY_RFC3986);
            [$code, , $headers] = $this->AuthHttp($ch, self::AUTH_BASE . '/oauth2/v2.0/authorize?' . $query, null, [], true);
            if ($code !== 200 || empty($headers['x-request-id'])) {
                throw new Exception('Authorize request failed (HTTP ' . $code . ')', self::STATUS_CONNECTION_ERROR);
            }

            $stateProperties = $this->Base64Url('{"TID":"' . $headers['x-request-id'] . '"}');
            $csrf = $this->GetCookie($ch, 'x-ms-cpim-csrf');
            if ($csrf === null) {
                throw new Exception('CSRF cookie not found', self::STATUS_CONNECTION_ERROR);
            }

            // 2. Zugangsdaten senden
            $query = http_build_query([
                'tx' => 'StateProperties=' . $stateProperties,
                'p'  => self::POLICY_PATH,
            ], '', '&', PHP_QUERY_RFC3986);
            [$code, $body] = $this->AuthHttp(
                $ch,
                self::AUTH_BASE . '/' . self::POLICY_PATH . '/SelfAsserted?' . $query,
                ['request_type' => 'RESPONSE', 'signInName' => $email, 'password' => $password],
                ['x-csrf-token: ' . $csrf],
                false
            );
            $json = json_decode((string) $body, true);
            if ($code !== 200 || ($json['status'] ?? '') !== '200') {
                throw new Exception('Login rejected: ' . ($json['message'] ?? ('HTTP ' . $code)), self::STATUS_LOGIN_FAILED);
            }

            // 3. Bestätigung abrufen – die Weiterleitung enthält den Autorisierungscode
            $query = http_build_query([
                'rememberMe' => 'false',
                'csrf_token' => $csrf,
                'tx'         => 'StateProperties=' . $stateProperties,
                'p'          => self::POLICY_PATH,
            ], '', '&', PHP_QUERY_RFC3986);
            [$code, , $headers] = $this->AuthHttp(
                $ch,
                self::AUTH_BASE . '/' . self::POLICY_PATH . '/api/CombinedSigninAndSignup/confirmed?' . $query,
                null,
                [],
                false
            );
            $location = $headers['location'] ?? '';
            parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
            if (empty($params['code'])) {
                throw new Exception('No authorization code received (HTTP ' . $code . ')', self::STATUS_LOGIN_FAILED);
            }
        } finally {
            curl_close($ch);
        }

        // 4. Code gegen Tokens tauschen
        return $this->RequestToken([
            'grant_type'    => 'authorization_code',
            'code'          => $params['code'],
            'redirect_uri'  => self::REDIRECT_URI,
            'code_verifier' => $codeVerifier,
            'client_id'     => self::CLIENT_ID,
        ]);
    }

    private function RequestToken(array $grant): array
    {
        $ch = curl_init(self::AUTH_BASE . '/oauth2/v2.0/token?p=' . self::POLICY);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($grant),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => self::USER_AGENT,
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('Token request failed: ' . $error, self::STATUS_CONNECTION_ERROR);
        }
        $json = json_decode($response, true);
        if ($code === 400) {
            throw new Exception('Token request returned 400: ' . ($json['error_description'] ?? $response), self::STATUS_LOGIN_FAILED);
        }
        if ($code >= 400 || empty($json['access_token'])) {
            throw new Exception('Token request returned HTTP ' . $code, self::STATUS_CONNECTION_ERROR);
        }
        return $json;
    }

    /**
     * @return array [HTTP-Code, Body, Header (Kleinbuchstaben) der letzten Antwort]
     */
    private function AuthHttp($ch, string $url, ?array $form, array $headers, bool $follow): array
    {
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_COOKIEFILE     => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$responseHeaders) {
                if (stripos($line, 'HTTP/') === 0) {
                    $responseHeaders = []; // neue Antwort (z. B. nach Redirect)
                }
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($form === null) {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }

        $body = curl_exec($ch);
        if ($body === false) {
            throw new Exception('HTTP error: ' . curl_error($ch), self::STATUS_CONNECTION_ERROR);
        }
        return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $body, $responseHeaders];
    }

    private function GetCookie($ch, string $name): ?string
    {
        // Netscape-Format: domain, flag, path, secure, expiry, name, value
        foreach (curl_getinfo($ch, CURLINFO_COOKIELIST) ?: [] as $line) {
            $fields = explode("\t", $line);
            if (count($fields) >= 7 && $fields[5] === $name) {
                return $fields[6];
            }
        }
        return null;
    }

    private function ClearTokens(): void
    {
        $this->WriteAttributeString('AccessToken', '');
        $this->WriteAttributeString('RefreshToken', '');
        $this->WriteAttributeInteger('TokenExpires', 0);
    }

    private function HandleError(Exception $e): void
    {
        $this->SendDebug('Error', $e->getMessage(), 0);
        $this->LogMessage($e->getMessage(), KL_ERROR);
        $this->SetStatus($e->getCode() === self::STATUS_LOGIN_FAILED ? self::STATUS_LOGIN_FAILED : self::STATUS_CONNECTION_ERROR);
    }

    // ---------------------------------------------------------------------
    // Variablen & Profile
    // ---------------------------------------------------------------------

    private function MaintainVariables(): void
    {
        $hotWater = $this->ReadPropertyBoolean('EnableHotWater');
        $energy = $this->ReadPropertyBoolean('EnableEnergy');

        // Klimazone (eTwist)
        $this->MaintainVariable('RoomTemperature', $this->Translate('Room temperature'), VARIABLETYPE_FLOAT, '~Temperature', 10, true);
        $this->MaintainVariable('SetPoint', $this->Translate('Setpoint'), VARIABLETYPE_FLOAT, 'RMH.Setpoint', 11, true);
        $this->MaintainVariable('ZoneMode', $this->Translate('Mode'), VARIABLETYPE_INTEGER, 'RMH.ZoneMode', 12, true);
        $this->MaintainVariable('TimeProgram', $this->Translate('Time program'), VARIABLETYPE_INTEGER, 'RMH.TimeProgram', 13, true);
        $this->MaintainVariable('FireplaceMode', $this->Translate('Fireplace mode'), VARIABLETYPE_BOOLEAN, '~Switch', 14, true);
        $this->MaintainVariable('HeatingActive', $this->Translate('Heating demand'), VARIABLETYPE_BOOLEAN, 'RMH.Active', 15, true);
        $this->MaintainVariable('CurrentScheduleSetPoint', $this->Translate('Current schedule setpoint'), VARIABLETYPE_FLOAT, '~Temperature', 16, true);
        $this->MaintainVariable('NextSetpoint', $this->Translate('Next setpoint'), VARIABLETYPE_FLOAT, '~Temperature', 17, true);
        $this->MaintainVariable('NextSwitchTime', $this->Translate('Next switch time'), VARIABLETYPE_INTEGER, '~UnixTimestamp', 18, true);

        $this->EnableAction('SetPoint');
        $this->EnableAction('ZoneMode');
        $this->EnableAction('TimeProgram');
        $this->EnableAction('FireplaceMode');

        // Gerät (Therme)
        $this->MaintainVariable('Online', $this->Translate('Online'), VARIABLETYPE_BOOLEAN, 'RMH.Online', 30, true);
        $this->MaintainVariable('WaterPressure', $this->Translate('Water pressure'), VARIABLETYPE_FLOAT, 'RMH.Pressure', 31, true);
        $this->MaintainVariable('OutdoorTemperature', $this->Translate('Outdoor temperature'), VARIABLETYPE_FLOAT, '~Temperature', 32, true);
        $this->MaintainVariable('CloudOutdoorTemperature', $this->Translate('Outdoor temperature (cloud)'), VARIABLETYPE_FLOAT, '~Temperature', 33, true);
        $this->MaintainVariable('ErrorStatus', $this->Translate('Appliance status'), VARIABLETYPE_STRING, '', 34, true);
        $this->MaintainVariable('ThermalMode', $this->Translate('Thermal mode'), VARIABLETYPE_STRING, '', 35, true);

        // Warmwasser
        $this->MaintainVariable('DHWTemperature', $this->Translate('Hot water temperature'), VARIABLETYPE_FLOAT, '~Temperature', 50, $hotWater);
        $this->MaintainVariable('DHWMode', $this->Translate('Hot water mode'), VARIABLETYPE_INTEGER, 'RMH.DHWMode', 51, $hotWater);
        $this->MaintainVariable('DHWTargetSetpoint', $this->Translate('Hot water target'), VARIABLETYPE_FLOAT, '~Temperature', 52, $hotWater);
        $this->MaintainVariable('DHWComfortSetpoint', $this->Translate('Hot water comfort setpoint'), VARIABLETYPE_FLOAT, 'RMH.DHWSetpoint', 53, $hotWater);
        $this->MaintainVariable('DHWReducedSetpoint', $this->Translate('Hot water eco setpoint'), VARIABLETYPE_FLOAT, 'RMH.DHWSetpoint', 54, $hotWater);
        $this->MaintainVariable('DHWActive', $this->Translate('Hot water heating'), VARIABLETYPE_BOOLEAN, 'RMH.Active', 55, $hotWater);
        if ($hotWater) {
            $this->EnableAction('DHWMode');
            $this->EnableAction('DHWComfortSetpoint');
            $this->EnableAction('DHWReducedSetpoint');
        }

        // Energie (heute)
        $this->MaintainVariable('HeatingEnergyConsumed', $this->Translate('Heating energy consumed today'), VARIABLETYPE_FLOAT, '~Electricity', 70, $energy);
        $this->MaintainVariable('HotWaterEnergyConsumed', $this->Translate('Hot water energy consumed today'), VARIABLETYPE_FLOAT, '~Electricity', 71, $energy);
        $this->MaintainVariable('HeatingEnergyDelivered', $this->Translate('Heating energy delivered today'), VARIABLETYPE_FLOAT, '~Electricity', 72, $energy);
        $this->MaintainVariable('HotWaterEnergyDelivered', $this->Translate('Hot water energy delivered today'), VARIABLETYPE_FLOAT, '~Electricity', 73, $energy);
    }

    private function RegisterProfiles(): void
    {
        $this->RegisterProfile('RMH.Setpoint', VARIABLETYPE_FLOAT, 'Temperature', '', ' °C', 5, 30, 0.5, 1);
        $this->RegisterProfile('RMH.DHWSetpoint', VARIABLETYPE_FLOAT, 'Drops', '', ' °C', 10, 65, 0.5, 1);
        $this->RegisterProfile('RMH.Pressure', VARIABLETYPE_FLOAT, 'Gauge', '', ' bar', 0, 4, 0.1, 1);

        $this->RegisterProfile('RMH.ZoneMode', VARIABLETYPE_INTEGER, 'Gear', '', '', 0, 3, 0, 0, [
            [self::MODE_SCHEDULE, $this->Translate('Schedule'), 'Clock', -1],
            [self::MODE_MANUAL, $this->Translate('Manual'), 'Hand', -1],
            [self::MODE_OFF, $this->Translate('Off (frost protection)'), 'Snowflake', -1],
            [self::MODE_TEMPORARY, $this->Translate('Temporary override'), 'Hourglass', -1],
        ]);
        $this->RegisterProfile('RMH.TimeProgram', VARIABLETYPE_INTEGER, 'Calendar', '', '', 1, 3, 0, 0, [
            [1, $this->Translate('Program 1'), '', -1],
            [2, $this->Translate('Program 2'), '', -1],
            [3, $this->Translate('Program 3'), '', -1],
        ]);
        $this->RegisterProfile('RMH.DHWMode', VARIABLETYPE_INTEGER, 'Drops', '', '', 0, 2, 0, 0, [
            [self::DHW_SCHEDULE, $this->Translate('Schedule'), 'Clock', -1],
            [self::DHW_COMFORT, $this->Translate('Comfort'), 'Sun', -1],
            [self::DHW_ECO, $this->Translate('Eco'), 'Leaf', -1],
        ]);
        $this->RegisterProfile('RMH.Active', VARIABLETYPE_BOOLEAN, 'Flame', '', '', 0, 0, 0, 0, [
            [false, $this->Translate('Idle'), '', -1],
            [true, $this->Translate('Heating'), '', 0xFF6600],
        ]);
        $this->RegisterProfile('RMH.Online', VARIABLETYPE_BOOLEAN, 'Network', '', '', 0, 0, 0, 0, [
            [false, $this->Translate('Offline'), '', 0xFF0000],
            [true, $this->Translate('Online'), '', 0x00AA00],
        ]);
    }

    private function RegisterProfile(string $name, int $type, string $icon, string $prefix, string $suffix, float $min, float $max, float $step, int $digits, array $associations = []): void
    {
        if (IPS_VariableProfileExists($name)) {
            $profile = IPS_GetVariableProfile($name);
            if ($profile['ProfileType'] !== $type) {
                IPS_DeleteVariableProfile($name);
            }
        }
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $type);
        }

        IPS_SetVariableProfileIcon($name, $icon);
        IPS_SetVariableProfileText($name, $prefix, $suffix);
        if ($type !== VARIABLETYPE_BOOLEAN && $type !== VARIABLETYPE_STRING) {
            IPS_SetVariableProfileValues($name, $min, $max, $step);
        }
        if ($type === VARIABLETYPE_FLOAT) {
            IPS_SetVariableProfileDigits($name, $digits);
        }
        foreach ($associations as [$value, $text, $assocIcon, $color]) {
            IPS_SetVariableProfileAssociation($name, (float) $value, $text, $assocIcon, $color);
        }
    }

    /**
     * Setzt eine Variable typgerecht, sofern sie existiert und ein Wert vorliegt.
     */
    private function SetVar(string $ident, $value): void
    {
        if ($value === null) {
            return;
        }
        $id = @$this->GetIDForIdent($ident);
        if ($id === false || $id === 0) {
            return;
        }

        switch (IPS_GetVariable($id)['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                $value = (bool) $value;
                break;
            case VARIABLETYPE_INTEGER:
                $value = (int) $value;
                break;
            case VARIABLETYPE_FLOAT:
                $value = (float) $value;
                break;
            default:
                $value = (string) $value;
        }
        $this->SetValue($ident, $value);
    }

    private function ParseTime(?string $time): ?int
    {
        if ($time === null || $time === '' || strpos($time, '0001-01-01') === 0) {
            return null;
        }
        $ts = strtotime($time);
        return $ts === false ? null : $ts;
    }

    private function Base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
