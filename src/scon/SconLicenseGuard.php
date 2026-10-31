<?php
declare(strict_types=1);

namespace pocketmine\scon;

use pocketmine\Server;
use pocketmine\VersionInfo;
use Symfony\Component\Filesystem\Path;
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
use function yaml_parse;
use const CURLOPT_CAINFO;
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

    public function __construct(private Server $server) {}

    /** Verifica al arrancar y programa las re-verificaciones. Devuelve false (y detiene el servidor) si no es válida. */
    public function start(): bool {
        $key = $this->resolveLicenseKey();
        if ($key === "" || str_contains($key, "XXXX")) {
            $this->shutdown("Clave de licencia no configurada. Configura la variable LICENSE_KEY en Pterodactyl o en scon-license.yml.");
            return false;
        }

        $result = $this->verifyLicense($key);
        if (!$result["valid"]) {
            $this->shutdown("Licencia rechazada ({$result["status"]}): {$result["message"]}");
            return false;
        }

        $this->server->getLogger()->info("§a[SCON] Licencia verificada exitosamente en licences.scon.host.");
        $this->server->getLogger()->info("§a[SCON] Proyecto: " . self::PROJECT_SLUG . " | Estado: ACTIVA");
        return true;
    }

    public function recheck(): void {
        $key = $this->resolveLicenseKey();
        if ($key === "") return;
        $r = $this->verifyLicense($key);
        if (!$r["valid"] && $r["status"] !== "NETWORK_ERROR") {
            $this->shutdown("Licencia rechazada durante re-verificacion ({$r["status"]}): {$r["message"]}");
        }
    }

    private function resolveLicenseKey(): string {
        $envKey = getenv("LICENSE_KEY");
        if (is_string($envKey) && trim($envKey) !== "") {
            return trim($envKey);
        }

        $file = Path::join($this->server->getDataPath(), "scon-license.yml");
        if (is_file($file)) {
            $content = @file_get_contents($file);
            if (is_string($content) && function_exists("yaml_parse")) {
                $yaml = @yaml_parse($content);
                if (is_array($yaml) && isset($yaml["license-key"]) && is_string($yaml["license-key"])) {
                    return trim($yaml["license-key"]);
                }
            }
        }

        // Intento adicional: archivo scon-license.key
        $keyFile = Path::join($this->server->getDataPath(), "scon-license.key");
        if (is_file($keyFile)) {
            $keyContent = @file_get_contents($keyFile);
            if (is_string($keyContent) && trim($keyContent) !== "") {
                return trim($keyContent);
            }
        }

        return "";
    }

    private function shutdown(string $reason): void {
        $logger = $this->server->getLogger();
        $logger->emergency("==========================================================");
        $logger->emergency("[SCON] ERROR CRITICO DE LICENCIA");
        $logger->emergency("[SCON] " . $reason);
        $logger->emergency("[SCON] El servidor QXRND PocketMine-MP se detendra de inmediato.");
        $logger->emergency("==========================================================");
        $this->server->forceShutdownExit();
    }

    /** @return array{valid: bool, status: string, message: string} */
    public function verifyLicense(string $key): array {
        $key = trim($key);
        if ($key === "" || str_contains($key, "XXXX")) {
            return ["valid" => false, "status" => "NO_KEY", "message" => "Configura license-key en Pterodactyl o scon-license.yml"];
        }
        if (!function_exists("sodium_crypto_sign_verify_detached")) {
            return ["valid" => false, "status" => "INVALID", "message" => "Este grado requiere la extension sodium de PHP"];
        }
        $nonce = bin2hex(random_bytes(24));
        $eph = self::newP384Key();
        if ($eph === null) {
            return ["valid" => false, "status" => "INVALID", "message" => "OpenSSL no soporta la curva P-384"];
        }
        $epk = self::spki($eph);
        $build = $this->buildFile();
        $body = [
            "protocol" => 2,
            "license_key" => $key,
            "project_slug" => self::PROJECT_SLUG,
            "product_name" => VersionInfo::NAME,
            "plugin_version" => VersionInfo::VERSION()->getFullVersion(true),
            "server_port" => $this->server->getPort(),
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
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ["Content-Type: application/json", "User-Agent: SCON-PHP/3"],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15
        ];

        // Certificado CA en Debian / Docker si existe
        if (is_file("/etc/ssl/certs/ca-certificates.crt")) {
            $curlOpts[CURLOPT_CAINFO] = "/etc/ssl/certs/ca-certificates.crt";
        }
        if (defined('CURLSSLOPT_NATIVE_CA') && defined('CURLOPT_SSL_OPTIONS')) {
            $curlOpts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }

        curl_setopt_array($ch, $curlOpts);
        $raw = curl_exec($ch);
        $res = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($res)) {
            return ["valid" => false, "status" => "NETWORK_ERROR", "message" => "No se pudo contactar con el servidor de licencias"];
        }
        if (!isset($res["payload"]) || !is_string($res["payload"]) || !self::verifySignatures($res)) {
            return ["valid" => false, "status" => "INVALID_SIGNATURE", "message" => "Firma del servidor invalida o ausente"];
        }
        $outer = json_decode($res["payload"], true);
        $encrypted = is_array($outer) && isset($outer["epk"]);
        $plain = $encrypted ? self::openEnvelope($outer, $nonce, $eph) : $res["payload"];
        $data = is_string($plain) ? json_decode($plain, true) : null;
        if (!is_array($data) || ($data["nonce"] ?? "") !== $nonce || ($data["project"] ?? "") !== self::PROJECT_SLUG) {
            return ["valid" => false, "status" => "REPLAY", "message" => "La respuesta no corresponde a esta peticion"];
        }
        if (abs(time() - (int) ($data["issued_at"] ?? 0)) > self::MAX_CLOCK_DRIFT_SECONDS) {
            return ["valid" => false, "status" => "CLOCK_DRIFT", "message" => "Desfase de reloj excesivo"];
        }
        // Una respuesta "valida" sin cifrar nunca se acepta (impide degradar el grado)
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
        $edKey = substr((string) base64_decode(self::ED25519_PUBLIC_KEY), -32);
        if ($sig2 === false || strlen($sig2) !== 64 || !sodium_crypto_sign_verify_detached($sig2, $payload, $edKey)) {
            return false;
        }
        return true;
    }

    /** ECDH P-384 efimero + HKDF-SHA384 -> AES-256-GCM. */
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

    private static function opensslConfig(): array {
        $config = ["private_key_type" => OPENSSL_KEYTYPE_EC, "curve_name" => "secp384r1"];
        if (PHP_OS_FAMILY === "Windows") {
            foreach ([
                dirname(PHP_BINARY) . "/extras/ssl/openssl.cnf",
                dirname(PHP_BINARY, 2) . "/extras/ssl/openssl.cnf",
                "C:/Program Files/Common Files/SSL/openssl.cnf",
            ] as $cnf) {
                if (is_file($cnf)) {
                    $config["config"] = $cnf;
                    break;
                }
            }
        }
        return $config;
    }

    private static function newP384Key(): ?\OpenSSLAsymmetricKey {
        $key = @openssl_pkey_new(self::opensslConfig());
        return $key === false ? null : $key;
    }

    private static function spki(\OpenSSLAsymmetricKey $key): string {
        $pem = (string) (openssl_pkey_get_details($key)["key"] ?? "");
        return (string) preg_replace('/-----[^-]+-----|\s+/', '', $pem);
    }

    private static function pem(string $spkiB64): string {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split($spkiB64, 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function dataDir(): string {
        $dir = Path::join($this->server->getDataPath(), "scon-license");
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    private function deviceKey(): \OpenSSLAsymmetricKey {
        $file = Path::join($this->dataDir(), "scon-device.key");
        if (is_file($file)) {
            $key = openssl_pkey_get_private((string) file_get_contents($file));
            if ($key !== false) return $key;
        }
        $key = self::newP384Key();
        $pem = "";
        $cfg = self::opensslConfig();
        if (isset($cfg["config"])) {
            openssl_pkey_export($key, $pem, null, ["config" => $cfg["config"]]);
        } else {
            openssl_pkey_export($key, $pem);
        }
        file_put_contents($file, $pem);
        @chmod($file, 0600);
        return $key;
    }

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
            $file = Path::join($this->dataDir(), "scon-hwid.id");
            $id = is_readable($file) ? trim((string) file_get_contents($file)) : "";
            if ($id === "") {
                $id = bin2hex(random_bytes(32));
                @file_put_contents($file, $id);
                @chmod($file, 0600);
            }
        }
        return hash("sha256", "scon-hwid-2|" . $id);
    }

    private function buildFile(): string {
        $file = \Phar::running(false);
        if (str_starts_with($file, "phar://")) $file = rtrim(substr($file, 7), "/");
        return is_file($file) ? $file : "";
    }
}
