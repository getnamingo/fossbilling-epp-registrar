<?php
/**
 * Offline test for FRED DNSSEC (keysets) in the patched epp.php.
 * Usage: php fred_dnssec_test.php /path/to/patched/epp.php /path/to/tembo/src
 */
[$eppFile, $temboSrc] = [$argv[1] ?? '', $argv[2] ?? ''];
spl_autoload_register(function ($c) use ($temboSrc) {
    $p = 'Pinga\\Tembo\\';
    if (strpos($c, $p) === 0) { $f = $temboSrc . '/' . str_replace('\\', '/', substr($c, strlen($p))) . '.php'; if (is_file($f)) require $f; }
});
class Registrar_Exception extends Exception {}
class Registrar_AdapterAbstract { public function getLog() { return new class { public function debug($m) {} }; } }
class Registrar_Domain {
    public array $ns = [];
    public function __construct(public string $name) {}
    public function getName() { return $this->name; }
    public function getNs1() { return $this->ns[0] ?? null; } public function getNs2() { return $this->ns[1] ?? null; }
    public function getNs3() { return $this->ns[2] ?? null; } public function getNs4() { return $this->ns[3] ?? null; }
}
require $eppFile;
use Pinga\Tembo\Exception\EppException;

class MiniFred extends Pinga\Tembo\Registries\FredEpp {
    public array $domains = [];   // name => ['nsset'=>id,'keyset'=>id|'']
    public array $nssets = [];
    public array $keysets = [];   // id => ['keys'=>[id=>[flags,protocol,alg,pubKey]], 'tech'=>[]]
    public function __construct() { $this->isLoggedIn = true; $this->disableLogging(); }
    private function reply(string $inner = ''): SimpleXMLElement {
        return simplexml_load_string('<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result>' . $inner . '</response></epp>');
    }
    private function keys(SimpleXMLElement $e): array {
        $o = [];
        if ($e === null) return $o;
        foreach (($e->dnskey ?? []) as $k) { $a = ['flags'=>(int)$k->flags,'protocol'=>(int)$k->protocol,'alg'=>(int)$k->alg,'pubKey'=>preg_replace('/\s+/','',(string)$k->pubKey)]; $o[implode('|',$a)] = $a; }
        return $o;
    }
    public function writeRequest($xml) {
        $x = simplexml_load_string($xml); $cmd = $x->command;
        $D = 'http://www.nic.cz/xml/epp/domain-1.4'; $K = 'http://www.nic.cz/xml/epp/keyset-1.3'; $N = 'http://www.nic.cz/xml/epp/nsset-1.2';
        if (isset($cmd->info)) {
            $d = $cmd->info->children($D);
            if (isset($d->info)) {
                $n = (string)$d->info->name; if (!isset($this->domains[$n])) throw new EppException('Object does not exist');
                $r = $this->domains[$n];
                return $this->reply('<resData><domain:infData xmlns:domain="'.$D.'"><domain:name>'.$n.'</domain:name><domain:nsset>'.$r['nsset'].'</domain:nsset>'
                    . ($r['keyset'] !== '' ? '<domain:keyset>'.$r['keyset'].'</domain:keyset>' : '') . '</domain:infData></resData>');
            }
            $k = $cmd->info->children($K);
            if (isset($k->info)) {
                $id = (string)$k->info->id; if (!isset($this->keysets[$id])) throw new EppException('Object does not exist');
                $o = '<keyset:id>'.$id.'</keyset:id>';
                foreach ($this->keysets[$id]['keys'] as $a) $o .= "<keyset:dnskey><keyset:flags>{$a['flags']}</keyset:flags><keyset:protocol>{$a['protocol']}</keyset:protocol><keyset:alg>{$a['alg']}</keyset:alg><keyset:pubKey>{$a['pubKey']}</keyset:pubKey></keyset:dnskey>";
                foreach ($this->keysets[$id]['tech'] as $t) $o .= "<keyset:tech>$t</keyset:tech>";
                return $this->reply('<resData><keyset:infData xmlns:keyset="'.$K.'">'.$o.'</keyset:infData></resData>');
            }
            $n = $cmd->info->children($N);
            if (isset($n->info)) {
                $id = (string)$n->info->id; $s = $this->nssets[$id]; $o = '<nsset:id>'.$id.'</nsset:id>';
                foreach ($s['ns'] as $nm => $ips) { $o .= '<nsset:ns><nsset:name>'.$nm.'</nsset:name>'; foreach ($ips as $ip) $o .= '<nsset:addr>'.$ip.'</nsset:addr>'; $o .= '</nsset:ns>'; }
                foreach ($s['tech'] as $t) $o .= '<nsset:tech>'.$t.'</nsset:tech>';
                return $this->reply('<resData><nsset:infData xmlns:nsset="'.$N.'">'.$o.'<nsset:reportlevel>0</nsset:reportlevel></nsset:infData></resData>');
            }
        }
        if (isset($cmd->create)) {
            $nn = $cmd->create->children($N);
            if (isset($nn->create)) {
                $c = $nn->create; $ns = [];
                foreach ($c->ns as $e) { $ns[(string)$e->name] = []; foreach ($e->addr as $ad) $ns[(string)$e->name][] = (string)$ad; }
                $tech = []; foreach ($c->tech as $t) $tech[] = (string)$t;
                $this->nssets[(string)$c->id] = ['ns'=>$ns,'tech'=>$tech]; return $this->reply();
            }
            $k = $cmd->create->children($K);
            if (isset($k->create)) {
                $id = (string)$k->create->id; if (isset($this->keysets[$id])) throw new EppException('Object exists');
                $keys = $this->keys($k->create); if (!$keys) throw new EppException('DNSKEY required');
                $tech = []; foreach ($k->create->tech as $t) $tech[] = (string)$t;
                $this->keysets[$id] = ['keys'=>$keys,'tech'=>$tech];
                return $this->reply();
            }
        }
        if (isset($cmd->update)) {
            $d = $cmd->update->children($D);
            if (isset($d->update)) {
                $n = (string)$d->update->name; if (!isset($this->domains[$n])) throw new EppException('Object does not exist');
                $chg = $d->update->chg;
                if (isset($chg->nsset)) $this->domains[$n]['nsset'] = (string)$chg->nsset;
                if (isset($chg->keyset)) {
                    $v = (string)$chg->keyset;
                    if ($v !== '' && !isset($this->keysets[$v])) throw new EppException('keyset does not exist');
                    $this->domains[$n]['keyset'] = $v;
                } elseif (isset($chg->nsset)) { $this->domains[$n]['keyset'] = ''; } // FRED unlinks keyset when nsset changes unless re-named
                return $this->reply();
            }
            $k = $cmd->update->children($K);
            if (isset($k->update)) {
                $id = (string)$k->update->id; if (!isset($this->keysets[$id])) throw new EppException('Object does not exist');
                foreach ($this->keys($k->update->rem) as $i => $_) { if (!isset($this->keysets[$id]['keys'][$i])) throw new EppException('DNSKEY not in keyset'); unset($this->keysets[$id]['keys'][$i]); }
                foreach ($this->keys($k->update->add) as $i => $a) $this->keysets[$id]['keys'][$i] = $a;
                if (!$this->keysets[$id]['keys']) throw new EppException('Keyset must keep at least one DNSKEY');
                return $this->reply();
            }
        }
        if (isset($cmd->delete)) {
            $nd = $cmd->delete->children($N);
            if (isset($nd->delete)) {
                $id = (string)$nd->delete->id;
                foreach ($this->domains as $r) if ($r['nsset'] === $id) throw new EppException('nsset is linked to a domain');
                unset($this->nssets[$id]); return $this->reply();
            }
            $k = $cmd->delete->children($K);
            if (isset($k->delete)) {
                $id = (string)$k->delete->id;
                foreach ($this->domains as $r) if ($r['keyset'] === $id) throw new EppException('keyset is linked to a domain');
                unset($this->keysets[$id]); return $this->reply();
            }
        }
        throw new EppException('Unexpected command: ' . substr($xml, 0, 100));
    }
}

class TestAdapter extends Registrar_Adapter_EPP {
    public MiniFred $fred; public array $served = []; public int $fetches = 0;
    public function epp_client() { return $this->fred; }
    public function epp_client_logout($epp) {}
    protected function fredFetchDnskeys(string $domainName, array $nsMap): array { $this->fetches++; return $this->served; }
    public function call(string $m, ...$a) { return (function () use ($m, $a) { return $this->$m(...$a); })->bindTo($this, Registrar_Adapter_EPP::class)(); }
}

$fail = 0;
function check(bool $c, string $m): void { global $fail; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; if (!$c) $fail++; }
function throws(callable $f, string $needle): bool { try { $f(); } catch (Registrar_Exception $e) { return str_contains($e->getMessage(), $needle); } return false; }

// ---- crypto vectors: root KSK-2017 (key tag 20326), IANA published DS
$root = ['flags'=>257,'protocol'=>3,'alg'=>8,'pubKey'=>'AwEAAaz/tAm8yTn4Mfeh5eyI96WSVexTBAvkMgJzkKTOiW1vkIbzxeF3+/4RgWOq7HrxRixHlFlExOLAJr5emLvN7SWXgnLh4+B5xQlNVz8Og8kvArMtNROxVQuCaSnIDdD5LKyWbRd2n9WGe2R8PzgCmr3EgVLrjyBxWezF0jLHwVN8efS3rCj/EWgvIWgb9tarpVUDK/b58Da+sqqls3eNbuv7pr+eoZG+SrDK6nWeL3c6H5Apxz7LjVc1uTIdsIXxuOLYA4/ilBmSVIzuDWfdRUfhHdY6+cn8HFRm+2hM8AnXGXws9555KrUB5qihylGa8subX2Nn6UwNR1AkUTV74bU='];
$a0 = new TestAdapter(['registry_profile'=>'FRED']);
$ds = $a0->call('fredDnskeyToDs', '.', $root, 2);
check($ds['key_tag'] === 20326 && $ds['algorithm'] === 8, 'root KSK key tag = 20326');
check($ds['digest'] === 'E06D44B80B8F1D39A95C0B0D7C65D08458E880409BBC683457104237C7F8EC8D', 'root KSK SHA-256 DS matches the IANA value');
check($a0->call('fredDnskeyToDs', '.', $root, 3) === null, 'unsupported digest type -> null');

// ---- DNS response parsing (compressed names, mixed record types)
$name = "\x09mytest786\x03org\x00";
$k1 = pack('nCC', 257, 3, 13) . str_repeat("\x01", 64);
$rr = fn(int $t, string $rd) => "\xC0\x0C" . pack('nnNn', $t, 1, 300, strlen($rd)) . $rd;
$resp = pack('nnnnnn', 1, 0x8180, 1, 3, 0, 0) . $name . pack('nn', 48, 1) . $rr(1, "\x01\x02\x03\x04") . $rr(48, $k1) . $rr(48, pack('nCC', 256, 3, 13) . str_repeat("\x02", 64));
$keys = $a0->call('fredParseDnskeyResponse', $resp);
check(count($keys) === 2 && $keys[0]['flags'] === 257 && $keys[0]['alg'] === 13 && base64_decode($keys[0]['pubKey']) === str_repeat("\x01", 64), 'DNS parser extracts only the DNSKEY records');
check($a0->call('fredParseDnskeyResponse', pack('nnnnnn', 1, 0x8183, 1, 0, 0, 0) . $name . pack('nn', 48, 1)) === [], 'NXDOMAIN -> no keys');
check($a0->call('fredParseDnskeyResponse', 'xx') === [], 'garbage -> no keys');

// ---- real TCP fetch against a local DNS mock on 127.0.0.1:53
$srv = @stream_socket_server('tcp://127.0.0.1:53', $en, $es);
if ($srv) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $c = stream_socket_accept($srv, 5); $len = unpack('n', fread($c, 2))[1]; $q = fread($c, $len);
        $out = substr($q, 0, 2) . pack('nnnnn', 0x8180, 1, 2, 0, 0) . substr($q, 12) . $rr(48, $k1) . $rr(48, pack('nCC', 256, 3, 13) . str_repeat("\x02", 64));
        fwrite($c, pack('n', strlen($out)) . $out); exit(0);
    }
    $a1 = new class(['registry_profile'=>'FRED']) extends Registrar_Adapter_EPP { public function f($d,$m){ return $this->fredFetchDnskeys($d,$m); } };
    $got = $a1->f('mytest786.org', ['ns1.mytest786.org' => ['127.0.0.1']]);
    pcntl_waitpid($pid, $st);
    check(count($got) === 2 && $got[0]['alg'] === 13, 'real TCP DNSKEY query against a local DNS mock works');
} else { echo "SKIP real DNS socket test (cannot bind :53: $es)\n"; }

// ---- FRED flows
function fresh(array $served = []): array {
    $f = new MiniFred();
    $f->nssets['NSID-A'] = ['ns'=>['ns1.mytest786.org'=>['1.2.3.4'],'ns2.example.net'=>[]],'tech'=>['CID-1']];
    $f->domains['mytest786.org'] = ['nsset'=>'NSID-A','keyset'=>''];
    $a = new TestAdapter(['registry_profile'=>'FRED','registrarprefix'=>'KANSAL']); $a->fred = $f; $a->served = $served;
    return [$a, $f, new Registrar_Domain('mytest786.org')];
}
$K1 = ['flags'=>257,'protocol'=>3,'alg'=>13,'pubKey'=>base64_encode(str_repeat("\x01", 64))];
$K2 = ['flags'=>256,'protocol'=>3,'alg'=>13,'pubKey'=>base64_encode(str_repeat("\x02", 64))];
$dsOf = fn(array $k) => (new TestAdapter(['registry_profile'=>'FRED']))->call('fredDnskeyToDs', 'mytest786.org', $k, 2);
$p = function(array $d, string $n = '1') { return ["keyTag_$n"=>$d['key_tag'],"alg_$n"=>$d['algorithm'],"digestType_$n"=>$d['digest_type'],"digest_$n"=>$d['digest']]; };

[$a,$f,$d] = fresh();
check($a->getDNSSEC($d) === [], 'getDNSSEC with no keyset -> empty list');

// add: key is on the nameservers -> keyset created and linked
[$a,$f,$d] = fresh([$K2, $K1]);
$r = $a->updateDNSSEC($d, ['command'=>'add'] + $p($dsOf($K1)));
$ks = $f->domains['mytest786.org']['keyset'];
check($r['code'] === 1000, 'updateDNSSEC add -> 1000');
check(str_starts_with($ks, 'KSID-') && str_ends_with($ks, '-KANSAL'), "keyset created and linked ($ks)");
check(count($f->keysets[$ks]['keys']) === 1 && array_values($f->keysets[$ks]['keys'])[0]['flags'] === 257, 'only the DNSKEY matching the DS was stored (not the ZSK)');
check($f->keysets[$ks]['tech'] === ['CID-1'], 'tech contact copied from the nsset');
check($a->getDNSSEC($d) === [$dsOf($K1)], 'getDNSSEC returns the DS derived from the stored key');

// add same again -> idempotent, no second keyset
$n = count($f->keysets); $a->updateDNSSEC($d, ['command'=>'add'] + $p($dsOf($K1)));
check(count($f->keysets) === 1 && $n === 1, 'adding an existing DS is a no-op');

// add second key -> keyset updated in place
$a->updateDNSSEC($d, ['command'=>'add'] + $p($dsOf($K2)));
check(count($f->keysets[$ks]['keys']) === 2 && $f->domains['mytest786.org']['keyset'] === $ks, 'second DS adds a second key to the same keyset');
check(count($a->getDNSSEC($d)) === 2, 'getDNSSEC lists both');

// addrem (key rollover): remove K1, add K3
$K3 = ['flags'=>257,'protocol'=>3,'alg'=>13,'pubKey'=>base64_encode(str_repeat("\x03", 64))];
$a->served = [$K2, $K3];
$a->updateDNSSEC($d, ['command'=>'addrem'] + $p($dsOf($K1), '1') + $p($dsOf($K3), '2'));
$flags = array_map(fn($k) => $k['pubKey'], $f->keysets[$ks]['keys']);
check(count($flags) === 2 && in_array($K3['pubKey'], $flags, true) && !in_array($K1['pubKey'], $flags, true), 'addrem swaps K1 for K3');

// rem: remove one, then the last one -> keyset unlinked and deleted
$a->updateDNSSEC($d, ['command'=>'rem'] + $p($dsOf($K3)));
check(count($f->keysets[$ks]['keys']) === 1, 'rem removes one key');
$a->updateDNSSEC($d, ['command'=>'rem'] + $p($dsOf($K2)));
check($f->domains['mytest786.org']['keyset'] === '' && !isset($f->keysets[$ks]), 'removing the last key unlinks and deletes the keyset');

// rem of an unknown DS
[$a,$f,$d] = fresh([$K1]); $a->updateDNSSEC($d, ['command'=>'add'] + $p($dsOf($K1)));
check(throws(fn() => $a->updateDNSSEC($d, ['command'=>'rem'] + $p($dsOf($K2))), 'not registered'), 'removing an unknown DS -> clear error');

// failures
[$a,$f,$d] = fresh([]);
check(throws(fn() => $a->updateDNSSEC($d, ['command'=>'add'] + $p($dsOf($K1))), 'No DNSKEY could be read'), 'zone not signed / NS silent -> clear error');
check($f->keysets === [] && $f->domains['mytest786.org']['keyset'] === '', 'nothing written after that error');
[$a,$f,$d] = fresh([$K2]);
check(throws(fn() => $a->updateDNSSEC($d, ['command'=>'add'] + $p($dsOf($K1))), 'None of the DNSKEYs'), 'DS that matches no published key is rejected');
$bad = $p($dsOf($K2)); $bad['digest_1'] = strrev($bad['digest_1']);
check(throws(fn() => $a->updateDNSSEC($d, ['command'=>'add'] + $bad), 'None of the DNSKEYs'), 'tampered digest is rejected');
check(throws(fn() => $a->updateDNSSEC($d, ['command'=>'add', 'keyTag_1'=>1]), 'Missing required'), 'missing fields rejected by the existing validation');

// NS change keeps the keyset (FRED would otherwise drop it)
[$a,$f,$d] = fresh([$K1]); $a->updateDNSSEC($d, ['command'=>'add'] + $p($dsOf($K1))); $ks = $f->domains['mytest786.org']['keyset'];
$d->ns = ['ns1.mytest786.org', 'ns3.example.org'];
$a->modifyNs($d);
check($f->domains['mytest786.org']['keyset'] === $ks && $f->domains['mytest786.org']['nsset'] !== 'NSID-A', 'nameserver change keeps the keyset linked');
$a->updateGlueHost($d, ['hostname'=>'ns1.mytest786.org','currentipaddress'=>'1.2.3.4','newipaddress'=>'8.8.4.4']);
check($f->domains['mytest786.org']['keyset'] === $ks, 'glue change keeps the keyset linked');

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
