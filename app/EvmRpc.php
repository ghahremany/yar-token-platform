<?php
declare(strict_types=1);

namespace App;

/**
 * Standard Ethereum / BNB Smart Chain JSON-RPC 2.0 Server (/rpc.php).
 * Directly compatible out-of-the-box with:
 * - Wallet Yar / Betcoin Android App (`Balances.java` eth_getBalance + ERC-20 eth_call balanceOf!)
 * - MetaMask / TrustWallet / Rabby custom RPC network + custom ERC-20 token!
 */
final class EvmRpc
{
    private const SCALE_INTERNAL = 8;  // DB stores 8 decimals
    private const SCALE_WEI_PAD  = 10; // 8 + 10 = 18 decimals (standard Ethereum/BSC wei!)

    /**
     * Converts an 8-decimal string (e.g. "125.50000000") cleanly to 0x-prefixed 18-decimal wei hex using GMP!
     */
    public static function tokenToWeiHex(string $amount): string
    {
        $norm = dec_norm($amount);
        if (str_starts_with($norm, '-') || bccomp($norm, '0', 8) <= 0) {
            return '0x0';
        }
        [$intPart, $fracPart] = explode('.', $norm);
        $weiDec = ltrim($intPart . str_pad($fracPart, self::SCALE_INTERNAL, '0') . str_repeat('0', self::SCALE_WEI_PAD), '0');
        if ($weiDec === '') {
            return '0x0';
        }
        $gmp = gmp_init($weiDec, 10);
        return '0x' . gmp_strval($gmp, 16);
    }

    public static function uint256WordHex(string|int $decInt): string
    {
        $gmp = gmp_init((string) $decInt, 10);
        return '0x' . str_pad(gmp_strval($gmp, 16), 64, '0', STR_PAD_LEFT);
    }

    public static function abiStringHex(string $str): string
    {
        $hex = bin2hex($str);
        $lenHex = str_pad(dechex(strlen($str)), 64, '0', STR_PAD_LEFT);
        $paddedData = $hex === '' ? '' : str_pad($hex, (int) (ceil(strlen($hex) / 64) * 64), '0', STR_PAD_RIGHT);
        return '0x' . str_pad('20', 64, '0', STR_PAD_LEFT) . $lenHex . $paddedData;
    }

    /**
     * Handles single or batch JSON-RPC 2.0 payloads.
     *
     * @param array<mixed> $payload
     * @return array<mixed>
     */
    public static function handle(array $payload): array
    {
        if (array_is_list($payload)) {
            $out = [];
            foreach ($payload as $item) {
                if (is_array($item)) {
                    $out[] = self::dispatchOne($item);
                }
            }
            return $out;
        }
        return self::dispatchOne($payload);
    }

    /**
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    private static function dispatchOne(array $req): array
    {
        $id = $req['id'] ?? 1;
        $method = (string) ($req['method'] ?? '');
        $params = is_array($req['params'] ?? null) ? $req['params'] : [];
        $chainId = max(1, (int) Settings::get('token_chain_id', '9898'));

        try {
            $result = match ($method) {
                'web3_clientVersion' => 'YarTokenCore/1.0.0/php8-mysql',
                'eth_chainId'        => '0x' . dechex($chainId),
                'net_version'        => (string) $chainId,
                'eth_gasPrice'       => '0x0', // Gasless native ledger transfers; protocol fees managed by TokenEngine
                'eth_estimateGas'    => '0x5208',
                'eth_blockNumber'    => '0x' . dechex(max(1, (int) Db::pdo()->query('SELECT COALESCE(MAX(id), 1) FROM ledger_entries')->fetchColumn())),

                'eth_getBalance' => (static function () use ($params): string {
                    $addr = (string) ($params[0] ?? '');
                    $u = Users::resolveRecipient($addr);
                    return $u ? self::tokenToWeiHex((string) $u['balance']) : '0x0';
                })(),

                'eth_getTransactionCount' => (static function () use ($params): string {
                    $addr = (string) ($params[0] ?? '');
                    $u = Users::resolveRecipient($addr);
                    return '0x' . dechex($u ? (int) $u['tx_nonce'] : 0);
                })(),

                'eth_call' => self::handleEthCall((array) ($params[0] ?? [])),

                'eth_getTransactionByHash', 'eth_getTransactionReceipt' => self::lookupTxReceipt((string) ($params[0] ?? ''), $method),

                default => throw new \InvalidArgumentException("Method not supported: {$method}", -32601),
            };

            return [
                'jsonrpc' => '2.0',
                'id'      => $id,
                'result'  => $result,
            ];
        } catch (\Throwable $e) {
            $code = $e->getCode() === -32601 ? -32601 : -32000;
            return [
                'jsonrpc' => '2.0',
                'id'      => $id,
                'error'   => [
                    'code'    => $code,
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    /** @param array<string,mixed> $callObj */
    private static function handleEthCall(array $callObj): string
    {
        $data = strtolower((string) ($callObj['data'] ?? $callObj['input'] ?? ''));
        $selector = substr($data, 0, 10);

        return match ($selector) {
            // balanceOf(address) -> 0x70a08231 + 32-byte padded address
            '0x70a08231' => (static function () use ($data): string {
                $addrHex = '0x' . substr($data, -40);
                $u = Users::resolveRecipient($addrHex);
                if (!$u) {
                    return '0x' . str_repeat('0', 64);
                }
                $weiHex = substr(self::tokenToWeiHex((string) $u['balance']), 2);
                return '0x' . str_pad($weiHex, 64, '0', STR_PAD_LEFT);
            })(),

            // totalSupply() -> 0x18160ddd
            '0x18160ddd' => (static function (): string {
                $sup = TokenEngine::supplyOverview();
                $weiHex = substr(self::tokenToWeiHex((string) $sup['net_total_supply']), 2);
                return '0x' . str_pad($weiHex, 64, '0', STR_PAD_LEFT);
            })(),

            // decimals() -> 0x313ce567 (returns 18 to match tokenToWeiHex scaling in EVM wallets!)
            '0x313ce567' => self::uint256WordHex(18),

            // symbol() -> 0x95d89b41
            '0x95d89b41' => self::abiStringHex(Settings::get('token_symbol', 'YAR')),

            // name() -> 0x06fdde03
            '0x06fdde03' => self::abiStringHex(Settings::get('token_name_en', 'YarCoin')),

            // feeBps() -> custom read helper 0x2f48ab7d / fallback
            default => '0x' . str_repeat('0', 64),
        };
    }

    /** @return ?array<string,mixed> */
    private static function lookupTxReceipt(string $txHash, string $method): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT l.*, su.evm_address AS from_addr, ru.evm_address AS to_addr
             FROM ledger_entries l
             LEFT JOIN users su ON su.id = l.from_user_id
             LEFT JOIN users ru ON ru.id = l.to_user_id
             WHERE l.tx_hash = ?
             LIMIT 1'
        );
        $stmt->execute([trim($txHash)]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $blockHex = '0x' . dechex((int) $row['id']);
        $zeroAddr = '0x0000000000000000000000000000000000000000';
        if ($method === 'eth_getTransactionReceipt') {
            return [
                'transactionHash'   => $row['tx_hash'],
                'blockNumber'       => $blockHex,
                'from'              => $row['from_addr'] ?: $zeroAddr,
                'to'                => $row['to_addr'] ?: $zeroAddr,
                'status'            => '0x1',
                'gasUsed'           => '0x5208',
                'cumulativeGasUsed' => '0x5208',
                'logs'              => [],
            ];
        }
        return [
            'hash'        => $row['tx_hash'],
            'blockNumber' => $blockHex,
            'from'        => $row['from_addr'] ?: $zeroAddr,
            'to'          => $row['to_addr'] ?: $zeroAddr,
            'value'       => self::tokenToWeiHex((string) $row['gross_amount']),
            'gasPrice'    => '0x0',
        ];
    }
}
