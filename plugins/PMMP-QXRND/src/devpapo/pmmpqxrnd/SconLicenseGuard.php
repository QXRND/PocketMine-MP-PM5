<?php
declare(strict_types=1);

namespace devpapo\pmmpqxrnd;

use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use OpenSSLAsymmetricKey;
use function abs;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function chmod;
use function chunk_split;
use function curl_exec;
use function curl_init;
use function curl_setopt_array;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function getenv;
use function hash;
use function hash_file;
use function hash_hkdf;
use function implode;
use function is_array;
use function is_file;
use function is_readable;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function openssl_decrypt;
use function openssl_pkey_export;
use function openssl_pkey_get_details;
use function openssl_pkey_get_private;
use function openssl_pkey_get_public;
use function openssl_pkey_new;
use function openssl_pkey_derive;
use function openssl_sign;
use function openssl_verify;
use function preg_replace;
use function random_bytes;
use function rtrim;
use function sodium_crypto_sign_verify_detached;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function time;
use function trim;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const OPENSSL_ALGO_SHA384;
use const OPENSSL_KEYTYPE_EC;
use const OPENSSL_RAW_DATA;
use const PHP_OS_FAMILY;

/**
 * SCON LICENSING — GRADO ÉLITE — Militar + doble firma Ed25519 + clave de dispositivo + SHA3-512
 * Proyecto: PMMP-QXRND (pmmp-qxrnd) · Protocolo v2 · PHP 8.1+ con openssl y sodium
 * Uso en onEnable(): if (!(new SconLicenseGuard($this))->start((string) $this->getConfig()->get("license-key", ""))) return;
 * Verifica al arrancar y vuelve a verificar cada 30 min; si la licencia deja de ser válida,
 * desactiva el plugin.
 */
final class SconLicenseGuard {

    private const VERIFY_URL = "https://licences.scon.host/api/v1/license/verify";
    private const PROJECT_SLUG = "pmmp-qxrnd";
    private const TIER = "military_ed25519";
    // Claves PÚBLICAS del proyecto: solo sirven para verificar, nunca para firmar
    private const ECDSA_P384_PUBLIC_KEY = "MHYwEAYHKoZIzj0CAQYFK4EEACIDYgAE9BMnUwTaB0pnte0tjMRg7AVHI1hscsxZhNku/hfSh5XDy9M3Yqpj+yzrv/PSKJpHnYkHUj5lSSRCDfBCN1BnZ5ggskOOQmhgqtaCADEDKhLoI9kiQXLayzcXpF5ZBO9X";
    private const ED25519_PUBLIC_KEY = "MCowBQYDK2VwAyEAkP2A+MJNYZeZacF2wn7FlYBbPPPAqajQHgyXKtbVOk0=";
    private const MAX_CLOCK_DRIFT_SECONDS = 120;
    private const RECHECK_MINUTES = 30;

    public function __construct(private PluginBase $plugin) {}

    /** Verifica y programa las re-verificaciones. Devuelve false (y desactiva el plugin) si no es válida. */
    public function start(string $key): bool {
        $result = $this->verifyLicense($key);
        if (!$result["valid"]) {
            $this->shutdown("Licencia rechazada ({$result["status"]}): {$result["message"]}");
            return false;
        }
        $this->plugin->getLogger()->info("[SCON] Licencia verificada.");
        $this->plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(function () use ($key): void {
            $r = $this->verifyLicense($key);
            if (!$r["valid"] && $r["status"] !== "NETWORK_ERROR") {
                $this->shutdown("Licencia rechazada ({$r["status"]}): {$r["message"]}");
            }
        }), 20 * 60 * self::RECHECK_MINUTES);
        return true;
    }

    private function shutdown(string $reason): void {
        $this->plugin->getLogger()->error("[SCON] " . $reason);
        $this->plugin->getServer()->getPluginManager()->disablePlugin($this->plugin);
    }

    /** @return array{valid: bool, status: string, message: string} */
    public function verifyLicense(string $key): array {
        $key = trim($key);
        if ($key === "" || str_contains($key, "XXXX")) {
            return ["valid" => false, "status" => "NO_KEY", "message" => "Configura license-key en config.yml"];
        }
        if (!function_exists("sodium_crypto_sign_verify_detached")) {
            return ["valid" => false, "status" => "INVALID", "message" => "Este grado requiere la extensión sodium de PHP"];
        }
        $nonce = bin2hex(random_bytes(24));
        $eph = self::newP384Key();
        if ($eph === null) {
            return ["valid" => false, "status" => "INVALID", "message" => "OpenSSL no soporta la curva P-384"];
        }
        $epk = self::spki($eph);
        $build = $this->buildFile();
        $description = $this->plugin->getDescription();
        $body = [
            "protocol" => 2,
            "license_key" => $key,
            "project_slug" => self::PROJECT_SLUG,
            "product_name" => $description->getName(),
            "plugin_version" => $description->getVersion(),
            "server_port" => $this->plugin->getServer()->getPort(),
            "client_nonce" => $nonce,
            "client_timestamp" => time(),
            "client_epk" => $epk,
            "hwid" => $this->computeHwid(),
            "binary_hash" => $build !== "" ? (string) hash_file("sha512", $build) : ""
        ];
        $body["binary_hash_sha3"] = $build !== "" ? (string) hash_file("sha3-512", $build) : "";
        $device = $this->deviceKey();
        $body["device_pub"] = self::spki($device);
        $message = implode("\n", ["SCON-DEV-1", self::PROJECT_SLUG, $key, $nonce, (string) $body["client_timestamp"],
            $body["hwid"], $body["binary_hash"], $body["binary_hash_sha3"], $epk]);
        $signature = "";
        openssl_sign($message, $signature, $device, OPENSSL_ALGO_SHA384);
        $body["device_sig"] = base64_encode($signature);

        $ch = curl_init(self::VERIFY_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ["Content-Type: application/json", "User-Agent: SCON-PHP/3"],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15
        ]);
        $raw = curl_exec($ch);
        $res = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($res)) {
            return ["valid" => false, "status" => "NETWORK_ERROR", "message" => "No se pudo contactar con el servidor de licencias"];
        }
        if (!isset($res["payload"]) || !is_string($res["payload"]) || !self::verifySignatures($res)) {
            return ["valid" => false, "status" => "INVALID_SIGNATURE", "message" => "Firma del servidor inválida o ausente"];
        }
        $outer = json_decode($res["payload"], true);
        $encrypted = is_array($outer) && isset($outer["epk"]);
        $plain = $encrypted ? self::openEnvelope($outer, $nonce, $eph) : $res["payload"];
        $data = is_string($plain) ? json_decode($plain, true) : null;
        if (!is_array($data) || ($data["nonce"] ?? "") !== $nonce || ($data["project"] ?? "") !== self::PROJECT_SLUG) {
            return ["valid" => false, "status" => "REPLAY", "message" => "La respuesta no corresponde a esta petición"];
        }
        if (abs(time() - (int) ($data["issued_at"] ?? 0)) > self::MAX_CLOCK_DRIFT_SECONDS) {
            return ["valid" => false, "status" => "CLOCK_DRIFT", "message" => "Desfase de reloj excesivo"];
        }
        // Una respuesta "válida" sin cifrar nunca se acepta (impide degradar el grado)
        if (!$encrypted && ($data["valid"] ?? false) === true) {
            return ["valid" => false, "status" => "UNENCRYPTED", "message" => "Respuesta sin cifrar: se rechaza"];
        }
        $valid = $encrypted && ($data["valid"] ?? false) === true;
        return ["valid" => $valid, "status" => (string) ($data["status"] ?? "INVALID"), "message" => (string) ($data["message"] ?? "")];
    }

    /** Firma ECDSA P-384/SHA-384 y Ed25519 del servidor sobre el texto exacto recibido. */
    private static function verifySignatures(array $res): bool {
        $payload = $res["payload"];
        $sig = base64_decode((string) ($res["sig"] ?? ""), true);
        if ($sig === false || $sig === "" || openssl_verify($payload, $sig, self::pem(self::ECDSA_P384_PUBLIC_KEY), OPENSSL_ALGO_SHA384) !== 1) {
            return false;
        }
        $sig2 = base64_decode((string) ($res["sig2"] ?? ""), true);
        $edKey = substr((string) base64_decode(self::ED25519_PUBLIC_KEY), -32); // SPKI DER → 32 bytes
        if ($sig2 === false || strlen($sig2) !== 64 || !sodium_crypto_sign_verify_detached($sig2, $payload, $edKey)) {
            return false;
        }
        return true;
    }

    /** ECDH P-384 efímero + HKDF-SHA384 → AES-256-GCM. La clave solo existe para esta petición. */
    private static function openEnvelope(array $env, string $nonce, \OpenSSLAsymmetricKey $clientKey): ?string {
        $serverKey = openssl_pkey_get_public(self::pem((string) ($env["epk"] ?? "")));
        if ($serverKey === false) return null;
        $shared = openssl_pkey_derive($serverKey, $clientKey);
        if ($shared === false) return null;
        $keys = hash_hkdf("sha384", $shared, 32, "SCON-ENV-1|" . self::TIER . "|" . self::PROJECT_SLUG, $nonce);
        $ct = (string) base64_decode((string) ($env["ct"] ?? ""));
        if (strlen($ct) < 16) return null;
        $data = openssl_decrypt(substr($ct, 0, -16), "aes-256-gcm", substr($keys, 0, 32), OPENSSL_RAW_DATA,
            (string) base64_decode((string) ($env["iv"] ?? "")), substr($ct, -16), $nonce);
        if ($data === false) return null;
        return $data;
    }

    private static function newP384Key(): ?\OpenSSLAsymmetricKey {
        $key = openssl_pkey_new(["private_key_type" => OPENSSL_KEYTYPE_EC, "curve_name" => "secp384r1"]);
        return $key === false ? null : $key;
    }

    /** Clave pública en SPKI DER (base64). */
    private static function spki(\OpenSSLAsymmetricKey $key): string {
        $pem = (string) (openssl_pkey_get_details($key)["key"] ?? "");
        return (string) preg_replace('/-----[^-]+-----|\s+/', '', $pem);
    }

    private static function pem(string $spkiB64): string {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split($spkiB64, 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** Clave propia de esta instalación (carpeta de datos del plugin → scon-device.key). Se crea la primera vez. */
    private function deviceKey(): \OpenSSLAsymmetricKey {
        $file = $this->plugin->getDataFolder() . "scon-device.key";
        if (is_file($file)) {
            $key = openssl_pkey_get_private((string) file_get_contents($file));
            if ($key !== false) return $key;
        }
        $key = self::newP384Key();
        $pem = "";
        openssl_pkey_export($key, $pem);
        @mkdir($this->plugin->getDataFolder(), 0700, true);
        file_put_contents($file, $pem);
        @chmod($file, 0600);
        return $key;
    }

    /** Identificador estable de la máquina: el machine-id del sistema (en Pterodactyl, el UUID del servidor) o, si no existe, uno aleatorio que se guarda la primera vez. No usa el nombre de máquina ni la MAC: cambian al reiniciar contenedores. */
    private function computeHwid(): string {
        $id = "";
        foreach (["/etc/machine-id", "/var/lib/dbus/machine-id"] as $file) {
            $id = is_readable($file) ? trim((string) file_get_contents($file)) : "";
            if ($id !== "") break;
        }
        if ($id === "" && PHP_OS_FAMILY === "Windows") {
            $id = (string) getenv("COMPUTERNAME") . (string) getenv("PROCESSOR_IDENTIFIER");
        }
        if ($id === "") {
            $file = $this->plugin->getDataFolder() . "scon-hwid.id";
            $id = is_readable($file) ? trim((string) file_get_contents($file)) : "";
            if ($id === "") {
                $id = bin2hex(random_bytes(32));
                @mkdir($this->plugin->getDataFolder(), 0700, true);
                if (@file_put_contents($file, $id) !== false) @chmod($file, 0600);
            }
        }
        return hash("sha256", "scon-hwid-2|" . $id);
    }

    /** Archivo .phar del plugin (tu build), para su huella. Vacío si se ejecuta desde una carpeta. */
    private function buildFile(): string {
        $file = $this->plugin->getFile();
        if (str_starts_with($file, "phar://")) $file = rtrim(substr($file, 7), "/");
        return is_file($file) ? $file : "";
    }
}
