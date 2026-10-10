<?php

declare(strict_types=1);

/**
 * Enigma2-Receiver (Dreambox, Vu+, Gigablue ...) ueber die Web-API des
 * Webinterface unter /web/. OpenWebIF und das DreamOS-Webinterface bieten
 * sie gleich an: Standby, Zustand, Sender, Lautstaerke, Fernbedienungstasten.
 *
 * Abfragen gehen per GET, Befehle per POST. Lehnt ein Webinterface einen
 * Befehl mit HTTP 412 ab (Sitzungsschutz), holt das Modul die Kennung von
 * /web/session und wiederholt den Befehl einmal damit.
 *
 * Benutzer und Passwort gehen nur als HTTP-Basic-Anmeldung an curl, nie in
 * die URL, das Debug-Fenster oder eine Meldung.
 */
class Enigma2Device extends IPSModule
{
    // Darstellungen von IP-Symcon (/usr/share/symcon/presentations/)
    private const P_AUFZ = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';
    private const P_SCHALTER = '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}';
    private const P_SCHIEBER = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';
    private const P_WERT = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';

    private const ZUSTAND = [0 => 'nicht erreichbar', 1 => 'Standby', 2 => 'eingeschaltet'];

    // /web/powerstate?newstate=
    private const WECKEN = 4;
    private const STANDBY = 5;

    // Status der Instanz (form.json)
    private const S_ABGELEHNT = 201;
    private const S_NICHT_ERREICHBAR = 202;

    /** Gesetzt, wenn ausfuehren() wegen eines laufenden Befehls nichts getan hat. */
    private bool $belegt = false;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 80);
        $this->RegisterPropertyBoolean('HTTPS', false);
        $this->RegisterPropertyString('Username', 'root');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('Interval', 5);
        $this->RegisterPropertyInteger('SwitchDelay', 15);

        $this->RegisterTimer('Update', 0, 'E2_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->registerVariables();

        $intervall = $this->ReadPropertyInteger('Interval');
        $aktiv = $this->host() !== '';
        $this->SetTimerInterval('Update', $aktiv && $intervall > 0 ? max(2, $intervall) * 1000 : 0);
        $this->SetStatus($aktiv ? IS_ACTIVE : IS_INACTIVE);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'POWER':
                $this->SetPower((bool) $Value);
                return;
            case 'VOLUME':
                $this->SetVolume((int) $Value);
                return;
            case 'MUTE':
                $this->SetMute((bool) $Value);
                return;
        }
        throw new Exception('Unbekannter Ident ' . $Ident);
    }

    // ===================== Oeffentliche Funktionen =====================

    /** Zustand abfragen und die Variablen setzen. */
    public function Update(): bool
    {
        // Ohne Warnung und ohne Warten: Der Zeitgeber soll einen ausgeschalteten
        // Receiver nicht ins Log schreiben und sich nicht hinter Befehlen stauen.
        return $this->zustandHolen(false, 0) > 0;
    }

    /**
     * 2 eingeschaltet, 1 Standby, 0 nicht erreichbar. Liefert die Antwort des
     * Receivers, auch in der Ruhezeit nach dem Schalten.
     */
    public function GetState(): int
    {
        return $this->zustandHolen(true, 5000);
    }

    /** Aufwecken (true) oder in den Standby schicken (false). */
    public function SetPower(bool $Value): bool
    {
        $ok = $this->ausfuehren(fn () => $this->standby($this->anfrage('powerstate', ['newstate' => $Value ? self::WECKEN : self::STANDBY], true))) !== null;
        if ($ok) {
            // Nach dem Wecken meldet der Receiver noch einige Sekunden Standby
            $this->SetBuffer('Geschaltet', (string) time());
            $this->setzen('POWER', $Value);
            $this->setzen('STATUS', $Value ? 2 : 1);
            if (!$Value) {
                $this->setzen('CHANNEL', '');
                $this->setzen('EVENT', '');
            }
        }
        return $ok;
    }

    /**
     * Taste der Fernbedienung druecken (Linux-Tastencode, z. B. 115 Lauter,
     * 114 Leiser, 113 Stumm, 402/403 Kanal +/-, 352 OK, 174 Exit, 2-11 Ziffern 1-0).
     */
    public function SendKey(int $Key): bool
    {
        return $this->ausfuehren(function () use ($Key) {
            $xml = $this->anfrage('remotecontrol', ['command' => $Key], true);
            if (!self::wahr(self::wert($xml, 'e2result') ?? 'true')) {
                throw new Exception('Taste ' . $Key . ' abgelehnt: ' . self::wert($xml, 'e2resulttext'));
            }
            return true;
        }) !== null;
    }

    /** Lautstaerke 0-100 setzen. */
    public function SetVolume(int $Level): bool
    {
        $Level = max(0, min(100, $Level));
        return $this->ausfuehren(fn () => $this->lautstaerkeSetzen($this->anfrage('vol', ['set' => 'set' . $Level], true))) !== null;
    }

    /** Ton stumm schalten (true) oder wieder an (false). */
    public function SetMute(bool $Mute): bool
    {
        return $this->ausfuehren(function () use ($Mute) {
            $xml = $this->anfrage('vol');
            // Das Webinterface kennt nur Umschalten
            if (self::wahr(self::wert($xml, 'e2ismuted')) !== $Mute) {
                $xml = $this->anfrage('vol', ['set' => 'mute'], true);
            }
            return $this->lautstaerkeSetzen($xml);
        }) !== null;
    }

    /** Auf einen Sender umschalten (Service-Referenz, z. B. aus E2_GetCurrent). */
    public function Zap(string $Reference): bool
    {
        return $this->ausfuehren(fn () => $this->pruefen($this->anfrage('zap', ['sRef' => $Reference], true), 'Umschalten')) !== null;
    }

    /** Meldung auf dem Fernseher anzeigen (Typ Info) fuer $Timeout Sekunden. */
    public function ShowMessage(string $Text, int $Timeout): bool
    {
        return $this->ausfuehren(fn () => $this->pruefen($this->anfrage('message', ['text' => $Text, 'type' => 1, 'timeout' => $Timeout], true), 'Meldung')) !== null;
    }

    /** Laufender Sender: Sender, Referenz, Sendung. Leer bei Fehler. */
    public function GetCurrent(): array
    {
        $xml = $this->ausfuehren(fn () => $this->anfrage('getcurrent'));
        if ($xml === null) {
            return [];
        }
        return [
            'Sender'   => self::text($xml, 'e2servicename'),
            'Referenz' => self::text($xml, 'e2servicereference'),
            'Sendung'  => self::text($xml, 'e2eventname'),
        ];
    }

    /** Modell und Versionen des Receivers. Leer bei Fehler. */
    public function GetInfo(): array
    {
        $xml = $this->ausfuehren(fn () => $this->anfrage('deviceinfo'));
        if ($xml === null) {
            return [];
        }
        $info = [];
        foreach (['Modell' => 'e2devicename', 'Enigma' => 'e2enigmaversion', 'Image' => 'e2imageversion', 'Webinterface' => 'e2webifversion'] as $name => $tag) {
            $wert = self::text($xml, $tag);
            if ($wert !== '') {
                $info[$name] = $wert;
            }
        }
        return $info;
    }

    // ===================== Zustand =====================

    private function zustandHolen(bool $warnen, int $warten): int
    {
        $ergebnis = $this->ausfuehren(function () {
            $an = !$this->standby($this->anfrage('powerstate'));
            return [$an, $an ? $this->anfrage('getcurrent') : ''];
        }, $warnen, $warten);

        $ruhe = time() - (int) $this->GetBuffer('Geschaltet') < $this->ReadPropertyInteger('SwitchDelay');
        if ($ergebnis === null) {
            if ($this->belegt) {
                return $this->GetValue('STATUS');
            }
            // POWER bleibt stehen: Ein Netz- oder HTTP-Fehler heisst weder an noch aus.
            // Beim Aufwachen beantwortet der Receiver einzelne Abfragen nicht; in der
            // Ruhezeit bleibt deshalb auch STATUS stehen.
            if ($ruhe) {
                $this->SendDebug('Update', 'Fehler in der Ruhezeit, Zustand bleibt stehen', 0);
            } else {
                $this->setzen('STATUS', 0);
            }
            return 0;
        }

        [$an, $aktuell] = $ergebnis;
        if ($ruhe) {
            $this->SendDebug('Update', 'Ruhezeit nach dem Schalten, Schalter bleibt stehen', 0);
        } else {
            $this->setzen('POWER', $an);
            $this->setzen('STATUS', $an ? 2 : 1);
            if (!$an) {
                $this->setzen('CHANNEL', '');
                $this->setzen('EVENT', '');
            }
        }
        // Nach einem Standby-Befehl nicht wieder befuellen, solange er noch wirkt
        if ($an && $this->GetValue('POWER')) {
            $this->setzen('CHANNEL', self::text($aktuell, 'e2servicename'));
            $this->setzen('EVENT', self::text($aktuell, 'e2eventname'));
            // DreamOS liefert die Lautstaerke in getcurrent mit, OpenWebIF nicht immer
            if (self::wert($aktuell, 'e2current') === null) {
                $aktuell = $this->ausfuehren(fn () => $this->anfrage('vol'), false, 0) ?? '';
            }
            if (self::wert($aktuell, 'e2current') !== null) {
                $this->lautstaerkeSetzen($aktuell);
            }
        }
        return $an ? 2 : 1;
    }

    /** true = Standby. Ohne eindeutige Antwort ein Fehler, nie "an". */
    private function standby(string $xml): bool
    {
        $wert = strtolower((string) self::wert($xml, 'e2instandby'));
        if ($wert !== 'true' && $wert !== 'false') {
            throw new Exception('Antwort ohne e2instandby');
        }
        return $wert === 'true';
    }

    private function lautstaerkeSetzen(string $xml): bool
    {
        $stufe = self::wert($xml, 'e2current');
        if ($stufe === null || !is_numeric($stufe)) {
            throw new Exception('Antwort ohne Lautstärke: ' . self::wert($xml, 'e2resulttext'));
        }
        $this->setzen('VOLUME', (int) $stufe);
        $this->setzen('MUTE', self::wahr(self::wert($xml, 'e2ismuted')));
        return true;
    }

    /** Antwort der Form <e2simplexmlresult><e2state>True ... pruefen. */
    private function pruefen(string $xml, string $was): bool
    {
        if (!self::wahr(self::wert($xml, 'e2state') ?? self::wert($xml, 'e2result'))) {
            throw new Exception($was . ' abgelehnt: ' . self::wert($xml, 'e2statetext'));
        }
        return true;
    }

    // ===================== Verbindung =====================

    /**
     * Fuehrt $befehl unter der Sperre der Instanz aus. Gibt dessen Ergebnis
     * zurueck oder null bei Fehler; mit $warnen als Warnung fuer den Aufrufer.
     */
    private function ausfuehren(callable $befehl, bool $warnen = true, int $warten = 5000)
    {
        $this->belegt = false;
        if ($this->host() === '') {
            return $this->fehler('Keine Adresse eingetragen', $warnen);
        }
        $sperre = 'E2_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sperre, $warten)) {
            $this->belegt = true;
            return $this->fehler('Receiver ist gerade belegt', $warnen);
        }
        try {
            return $befehl();
        } catch (Exception $e) {
            return $this->fehler($e->getMessage(), $warnen);
        } finally {
            IPS_SemaphoreLeave($sperre);
        }
    }

    private function fehler(string $text, bool $warnen)
    {
        $this->SendDebug('Fehler', $text, 0);
        if ($warnen) {
            trigger_error($text, E_USER_WARNING);
        }
        return null;
    }

    /** Antwort von /web/$befehl. $schalten: POST, sonst GET. */
    private function anfrage(string $befehl, array $parameter = [], bool $schalten = false): string
    {
        $pfad = $befehl . ($parameter ? '?' . http_build_query($parameter) : '');
        [$code, $antwort] = $this->http($pfad, $schalten ? '' : null);

        if ($code == 412 && $schalten) {
            $sitzung = self::wert($this->anfrage('session'), 'e2sessionid');
            $this->SendDebug('HTTP', 'Sitzungsschutz, wiederhole mit Sitzungskennung', 0);
            [$code, $antwort] = $this->http($pfad, http_build_query(['sessionid' => (string) $sitzung]));
        }
        if ($code == 401) {
            $this->statusSetzen(self::S_ABGELEHNT);
            throw new Exception('Anmeldung abgelehnt (HTTP 401), Benutzer und Passwort prüfen');
        }
        if ($code != 200) {
            throw new Exception('HTTP ' . $code . ' auf /web/' . $befehl);
        }
        $this->statusSetzen(IS_ACTIVE);
        return $antwort;
    }

    /** [HTTP-Code, Antwort]. $post null = GET, sonst POST mit diesem Inhalt. */
    private function http(string $pfad, ?string $post): array
    {
        $https = $this->ReadPropertyBoolean('HTTPS');
        $url = ($https ? 'https' : 'http') . '://' . $this->host() . ':' . $this->ReadPropertyInteger('Port') . '/web/' . $pfad;

        $optionen = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 4,
        ];
        if ($post !== null) {
            $optionen[CURLOPT_POST] = true;
            $optionen[CURLOPT_POSTFIELDS] = $post;
        }
        if ($https) {
            // Receiver haben selbst signierte Zertifikate
            $optionen[CURLOPT_SSL_VERIFYPEER] = false;
            $optionen[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        $benutzer = $this->ReadPropertyString('Username');
        if ($benutzer !== '') {
            $optionen[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $optionen[CURLOPT_USERNAME] = $benutzer;
            $optionen[CURLOPT_PASSWORD] = $this->ReadPropertyString('Password');
        }
        $c = curl_init($url);
        curl_setopt_array($c, $optionen);
        $antwort = curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);

        if ($antwort === false) {
            $text = curl_error($c);
            $this->SendDebug('HTTP', ($post === null ? 'GET ' : 'POST ') . $pfad . ' -> ' . $text, 0);
            $this->statusSetzen(self::S_NICHT_ERREICHBAR);
            throw new Exception('Receiver nicht erreichbar (' . $text . ')');
        }
        $this->SendDebug('HTTP', ($post === null ? 'GET ' : 'POST ') . $pfad . ' -> ' . $code, 0);
        $this->SendDebug('Antwort', $antwort, 0);
        return [$code, $antwort];
    }

    private function host(): string
    {
        $host = trim($this->ReadPropertyString('Host'));
        return trim((string) preg_replace('#^https?://#i', '', $host), '/');
    }

    private function statusSetzen(int $status): void
    {
        if ($this->GetStatus() != $status) {
            $this->SetStatus($status);
        }
    }

    // ===================== Variablen =====================

    private function registerVariables(): void
    {
        $this->RegisterVariableBoolean('POWER', 'Eingeschaltet', self::pSchalter('Power'), 1);
        $this->EnableAction('POWER');

        $this->RegisterVariableInteger('STATUS', 'Zustand', self::pAufz(self::ZUSTAND, 'Information'), 2);
        $this->RegisterVariableString('CHANNEL', 'Sender', ['PRESENTATION' => self::P_WERT, 'ICON' => 'Tv'], 3);
        $this->RegisterVariableString('EVENT', 'Sendung', ['PRESENTATION' => self::P_WERT, 'ICON' => 'Information'], 4);

        $this->RegisterVariableInteger('VOLUME', 'Lautstärke', [
            'PRESENTATION' => self::P_SCHIEBER, 'MIN' => 0, 'MAX' => 100, 'STEP_SIZE' => 5,
            'DIGITS' => 0, 'SUFFIX' => ' %', 'ICON' => 'Speaker',
        ], 5);
        $this->EnableAction('VOLUME');

        $this->RegisterVariableBoolean('MUTE', 'Stumm', self::pSchalter('Speaker'), 6);
        $this->EnableAction('MUTE');
    }

    /** Nur bei Aenderung schreiben: Die Abfrage laeuft alle paar Sekunden. */
    private function setzen(string $ident, $wert): void
    {
        if ($this->GetValue($ident) !== $wert) {
            $this->SetValue($ident, $wert);
        }
    }

    /** Inhalt des ersten <$tag>, null wenn es ihn nicht gibt. */
    private static function wert(string $xml, string $tag): ?string
    {
        if (!preg_match('#<' . $tag . '>(.*?)</' . $tag . '>#s', $xml, $m)) {
            return null;
        }
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    /** Wie wert(), aber "" statt null und statt "None" (OpenWebIF ohne Sendung). */
    private static function text(string $xml, string $tag): string
    {
        $wert = self::wert($xml, $tag) ?? '';
        return $wert === 'None' ? '' : $wert;
    }

    private static function wahr(?string $wert): bool
    {
        return strtolower((string) $wert) === 'true';
    }

    private static function pAufz(array $optionen, string $icon): array
    {
        $o = [];
        foreach ($optionen as $wert => $text) {
            $o[] = ['Value' => $wert, 'Caption' => $text, 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        return ['PRESENTATION' => self::P_AUFZ, 'OPTIONS' => json_encode($o), 'ICON' => $icon, 'LAYOUT' => 0];
    }

    private static function pSchalter(string $icon): array
    {
        return ['PRESENTATION' => self::P_SCHALTER, 'ICON_TRUE' => $icon, 'ICON_FALSE' => $icon, 'USE_ICON_FALSE' => false, 'USAGE_TYPE' => 0];
    }
}
