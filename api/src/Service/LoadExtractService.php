<?php

namespace App\Service;

use App\Entity\Adres;
use App\Entity\Mutation;
use App\Entity\OpenbareRuimte;
use App\Entity\Woonplaats;
use Doctrine\Common\Collections\ArrayCollection;
use GuzzleHttp\Client;
use Symfony\Component\Cache\Adapter\AdapterInterface as CacheInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

class LoadExtractService
{
    private EntityManagerInterface $entityManager;
    private SymfonyStyle $io;
    private XmlEncoder $xmlEncoder;
    private CacheInterface $cache;
    private Client $client;
    private ParameterBagInterface $parameterBag;


    public function __construct(EntityManagerInterface $entityManager, SymfonyStyle $io, CacheInterface $cache, ParameterBagInterface $parameterBag)
    {
        $this->entityManager = $entityManager;
        $this->xmlEncoder = new XmlEncoder(['xml_root_node_name' => 'soap:Envelope']);
        $this->io = $io;
        $this->cache = $cache;
        $this->client = new Client(['cookies' => true]);
        $this->parameterBag = $parameterBag;

        ini_set('memory_limit', '1G');
    }

    private function getSoapExtractMessage(): string
    {
        return '
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
            xmlns:v20="http://www.kadaster.nl/schemas/gds2/make2stock/v20201201">
            <soapenv:Header/>
            <soapenv:Body>
                <v20:BestandenlijstOpvragenRequest>
                    <v20:Artikelnummers>2536</v20:Artikelnummers>   
                </v20:BestandenlijstOpvragenRequest>
            </soapenv:Body>
        </soapenv:Envelope>';
    }

    private function getSoapUpdateMessage(\DateTime $start, \DateTime $endOriginal): string
    {
        $end = clone $endOriginal;

        $end->modify('-1 second');
        return '
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
            xmlns:v20="http://www.kadaster.nl/schemas/gds2/make2stock/v20201201">
            <soapenv:Header/>
            <soapenv:Body>
                <v20:BestandenlijstOpvragenRequest>
                    <v20:Periode>
                        <v20:DatumTijdVanaf>'.$start->format('Y-m-d\TH:i:s').'</v20:DatumTijdVanaf>
                        <v20:DatumTijdTotEnMet>'.$end->format('Y-m-d\TH:i:s').'</v20:DatumTijdTotEnMet>
                    </v20:Periode>
                    <v20:Artikelnummers>2529</v20:Artikelnummers>
                </v20:BestandenlijstOpvragenRequest>
            </soapenv:Body>
        </soapenv:Envelope>';
    }

    public function getStartDate(CacheItem $item, \DateTime $end): \DateTime
    {
        if($item->isHit()){
            $start = new \DateTime($item->get());
        } elseif($end->format('d') < 8) {
            $start = new \DateTime('first day of last month');
            $start->modify('+7 days');
            $start->modify('midnight');
        } else {
            $start = new \DateTime('first day of this month');
            $start->modify('+7 days');
            $start->modify('midnight');
        }
        return $start;
    }

    public function unzipFile(string $filename, string $destination, ?array $entries = null){
        $zip = new \ZipArchive();
        if($zip->open($filename) === true){
            $zip->extractTo($destination, $entries);
            $zip->close();
        }
    }

    public function getZip(string $id, string $filename): void
    {
        $this->client->post('https://mijn.kadaster.nl/security/login.do', ['form_params' => ['user'=>$this->parameterBag->get('kadaster-username'),'password' => $this->parameterBag->get('kadaster-password')]])->getBody()->getContents();

        fopen($filename, 'w');
        $this->client->get("https://service10.kadaster.nl/gds2/download/productstore/$id", ['sink' => $filename]);
    }

    public function processMutationFile(array $fileDescription): void
    {
        $filename = dirname(__FILE__, 3).'/var/import/zip/mutaties/mutaties.zip';
        $this->getZip($fileDescription['ns3:AfgifteID'], $filename);
        $this->unzipFile($filename, dirname(__FILE__, 3).'/var/import/zip/mutaties');
        unlink($filename);
        foreach(array_diff(scandir(dirname(__FILE__, 3).'/var/import/zip/mutaties'), array('..', '.')) as $file){
            if(strpos($file, '9999MUT') !== false){
                $this->unzipFile(dirname(__FILE__, 3)."/var/import/zip/mutaties/$file", dirname(__FILE__, 3).'/var/import/mutaties');
                $this->io->text("extracted $file");
            }
            unlink(dirname(__FILE__, 3)."/var/import/zip/mutaties/$file");
            $this->io->text("removed $file");
        }
        $this->processMutations();
    }

    public function processMutationFiles(array $files): void
    {
        foreach($files as $file){
            $this->processMutationFile($file);
        }
    }

    public function getDayMutations(): void
    {
        $item = $this->cache->getItem('databaseLastUpdate');
        $end = new \DateTime('midnight today');
        $start = $this->getStartDate($item, $end);

        $message = $this->getSoapUpdateMessage($start, $end);
        $response = $this->client->post('https://service10.kadaster.nl/gds2/afgifte/productstore', ['auth' => [$this->parameterBag->get('kadaster-username'),$this->parameterBag->get('kadaster-password')], 'body' => $message]);
        $content = $this->xmlEncoder->decode($response->getBody()->getContents(), 'xml');
        var_dump($content);
        if(isset($content['SOAP-ENV:Body']["ns3:BestandenlijstOpvragenResponse"]["ns3:BestandAfgiftes"])){
            $this->processMutationFiles(array_reverse($content['SOAP-ENV:Body']["ns3:BestandenlijstOpvragenResponse"]["ns3:BestandAfgiftes"]));
        }

        $item->set($end->format('Y-m-d'));
        $this->cache->save($item);
    }

    public function processExtractFiles(string $file): void
    {
        if(strpos($file, '9999WPL') !== false){
            $this->unzipFile(dirname(__FILE__, 3)."/var/import/zip/extract/$file", dirname(__FILE__, 3).'/var/import/woonplaatsen');
            $this->io->text("extracted $file");
        }
        if(strpos($file, '9999OPR') !== false){
            $this->unzipFile(dirname(__FILE__, 3)."/var/import/zip/extract/$file", dirname(__FILE__, 3).'/var/import/openbareruimtes');
            $this->io->text("extracted $file");
        }
        if(strpos($file, '9999NUM') !== false){
            $this->unzipFile(dirname(__FILE__, 3)."/var/import/zip/extract/$file", dirname(__FILE__, 3).'/var/import/nummeraanduidingen');
            $this->io->text("extracted $file");
        }
        if(strpos($file, '9999VBO') !== false){
            $this->unzipFile(dirname(__FILE__, 3)."/var/import/zip/extract/$file", dirname(__FILE__, 3).'/var/import/verblijfsobjecten');
            $this->io->text("extracted $file");
        }
    }

    public function processExtractFile(array $fileDescription): void
    {
        $filename = dirname(__FILE__, 3).'/var/import/zip/extract/extract.zip';
        $this->getZip($fileDescription['ns3:AfgifteID'], $filename);
        $this->unzipFile($filename, dirname(__FILE__, 3).'/var/import/zip/extract');
        unlink($filename);
        foreach(array_diff(scandir(dirname(__FILE__, 3).'/var/import/zip/extract'), array('..', '.')) as $file){
            $this->processExtractFiles($file);
            unlink(dirname(__FILE__, 3)."/var/import/zip/extract/$file");
            $this->io->text("removed $file");
        }
    }


    public function getExtract(): void
    {

        $message = $this->getSoapExtractMessage();
        $response = $this->client->post('https://service10.kadaster.nl/gds2/afgifte/productstore', ['auth' => [$this->parameterBag->get('kadaster-username'),$this->parameterBag->get('kadaster-password')], 'body' => $message]);
        $content = $this->xmlEncoder->decode($response->getBody()->getContents(), 'xml');

        if(isset($content['SOAP-ENV:Body']["ns3:BestandenlijstOpvragenResponse"]["ns3:BestandAfgiftes"][0])){
            $this->processExtractFile($content['SOAP-ENV:Body']["ns3:BestandenlijstOpvragenResponse"]["ns3:BestandAfgiftes"][0]);
        }

    }

    public function compareArrays(array $array1, array $array2, $prefix = null): array
    {
        $result = [];
        foreach($array1 as $key=>$value){
            if(!key_exists($key, $array2)){
                $result[] = $prefix ? $prefix.'.'.$key : $key;
            } elseif(is_array($value) && is_array($array2[$key])) {
                $result = array_merge($this->compareArrays($value, $array2[$key], $prefix ? $prefix.'.'.$key : $key), $result);
            } elseif(!is_array($value) && !is_array($array2[$key]) && $value == $array2[$key]) {
                $result[] = $prefix ? $prefix.'.'.$key : $key;
            } else {
                $result[] = $prefix ? $prefix.'.'.$key : $key;
            }
        }
        return $result;
    }

    public function createMutationResource(string $type, array $changed, array $resource): void
    {
        $mutation = new Mutation();
        $mutation->setObjectId($resource['Objecten:identificatie']['#']);
        $mutation->setObjectType($type);
        $mutation->setChangedFields($changed);
        $mutation->setDateCreated(new \DateTime('now'));

        $this->entityManager->persist($mutation);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $mutation = null;
        gc_collect_cycles();
    }

    public function handleAddition($mutation, $type, $comparison): void
    {
        switch($type){
            case 'Objecten:Woonplaats':
                $this->loadWoonplaats($mutation[$type]);
                $this->createMutationResource(explode(':', $type)[1], $comparison, $mutation[$type]);
                break;
            case 'Objecten:OpenbareRuimte':
                $this->loadOpenbareRuimte($mutation[$type]);
                $this->createMutationResource(explode(':', $type)[1], $comparison, $mutation[$type]);
                break;
            case 'Objecten:Verblijfsobject':
                $this->loadVerblijfsObject($mutation[$type]);
                $this->createMutationResource(explode(':', $type)[1], $comparison, $mutation[$type]);
                break;
            case 'Objecten:Nummeraanduiding':
                $this->loadNummerObject($mutation[$type]);
                $this->createMutationResource(explode(':', $type)[1], $comparison, $mutation[$type]);
                break;
            default:
                break;
        }
    }

    function isAssoc(array $arr): bool
    {
        if (array() === $arr) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    public function getNext(array $mutation): array
    {
        $results = [];
        if(
            key_exists('ml:toevoeging', $mutation) &&
            key_exists('ml:wordt', $mutation['ml:toevoeging'])
        ){
            $results[] = $mutation['ml:toevoeging']['ml:wordt']['mlm:bagObject'];
        } elseif(
            key_exists('ml:toevoeging', $mutation) &&
            !$this->isAssoc($mutation['ml:toevoeging'])
        ){
            foreach($mutation['ml:toevoeging'] as $change){
                $results[] = $change['ml:wordt']['mlm:bagObject'];
            }
        } else {
            $results[] = null;
        }
        return $results;
    }

    public function getPrevious(array $mutation): array
    {
        $results = [];
        if(
            key_exists('ml:wijziging', $mutation) &&
            key_exists('ml:was', $mutation['ml:wijziging'])
        ){
            $results[] = $mutation['ml:wijziging']['ml:was']['mlm:bagObject'];
        } elseif(
            key_exists('ml:wijziging', $mutation) &&
            !$this->isAssoc($mutation['ml:wijziging'])
        ){
            foreach($mutation['ml:wijziging'] as $change){
                $results[] = $change['ml:was']['mlm:bagObject'];
            }
        } else {
            $results[] = [];
        }
        return $results;
    }

    public function handleMutation(array $mutation): void
    {
        $previous = $this->getPrevious($mutation);
        $next = $this->getNext($mutation);
        $comparison = $this->compareArrays($next, $previous);
        foreach($next as $key=>$value){
            if(!$value){
                //@TODO: remove method
                continue;
            }
            $comp = array_filter($comparison, function($value) use($key){return substr($value,0, strlen($key)) == $key;});
            $this->handleAddition($value, $this->getObjectType($value), $comp);
        }
        $previous = [];
        $next = [];
        $comparison = [];
    }

    public function getObjectType(array $bagObject): string
    {
        return array_keys($bagObject)[0];
    }

    public function loadMutationsPerFile(string $filename): void
    {
        $data = $this->loadXmlFile($filename);
        if(isset($data['ml:mutatieBericht']['ml:mutatieGroep'])){
            $this->io->text("Used {$this->convert(memory_get_usage())} of memory");
            $this->io->createProgressBar(count($data['ml:mutatieBericht']['ml:mutatieGroep']));
            $this->io->progressStart(count($data['ml:mutatieBericht']['ml:mutatieGroep']));
            foreach($data['ml:mutatieBericht']['ml:mutatieGroep'] as $mutation){
                $this->handleMutation($mutation);
                $this->io->progressAdvance();
                $mutation = [];
                gc_collect_cycles();
            }
            $this->io->progressFinish();
        }
        $data = [];
        unlink($filename);
        gc_collect_cycles();
    }

    public function processMutations(): void
    {
        $filenames = array_diff(scandir(dirname(__FILE__, 3).'/var/import/mutaties'), array('..', '.'));
        foreach($filenames as $filename){
            $this->io->section("Loading $filename");
            $this->loadMutationsPerFile(dirname(__FILE__, 3).'/var/import/mutaties/'.$filename);
        }
    }

    public function loadXmlFile(string $filename): array
    {
        $file = file_get_contents($filename);
        return $this->xmlEncoder->decode($file, 'xml');
    }

    function convert($size)
    {
        $unit=array('b','kb','mb','gb','tb','pb');
        return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.$unit[$i];
    }

    public function loadNummerObject(array $nummeraanduidingArray): void
    {
//        var_dump($nummeraanduidingArray);
        if(!($nummeraanduiding = $this->entityManager->getRepository("App:Adres")->findOneBy(['id' => $nummeraanduidingArray['Objecten:identificatie']['#']]))){
            $nummeraanduiding = new Adres();
        } else {
//            $this->io->error('MUTATIE!');
        }

        isset($nummeraanduidingArray['Objecten:huisnummer']) ? $nummeraanduiding->setHuisnummer($nummeraanduidingArray['Objecten:huisnummer']) : null;
        isset($nummeraanduidingArray['Objecten:huisletter']) ? $huisnummerToevoeging = $nummeraanduidingArray['Objecten:huisletter'] : null;
        isset($nummeraanduidingArray['Objecten:huisnummertoevoeging']) ? (isset($huisnummerToevoeging) ? $huisnummerToevoeging .= " {$nummeraanduidingArray['Objecten:huisnummertoevoeging']}" : $huisnummerToevoeging = $nummeraanduidingArray['Objecten:huisnummertoevoeging']) : null;
        isset($nummeraanduidingArray['Objecten:postcode']) ? $nummeraanduiding->setPostcode($nummeraanduidingArray['Objecten:postcode']) : null;
        isset($nummeraanduidingArray['Objecten:status']) ? $nummeraanduiding->setStatusNummeraanduiding($nummeraanduidingArray['Objecten:status']) : null;
        isset($huisnummerToevoeging) ? $nummeraanduiding->setHuisnummertoevoeging($huisnummerToevoeging) : null;
        if(
            isset($nummeraanduidingArray['Objecten:ligtAan']["Objecten-ref:OpenbareRuimteRef"]['#']) &&
            $openbareRuimte = $this->entityManager->getRepository('App:OpenbareRuimte')->findOneBy(['id' => $nummeraanduidingArray['Objecten:ligtAan']["Objecten-ref:OpenbareRuimteRef"]['#']])
        ){
            $nummeraanduiding->setStatusOpenbareRuimte($openbareRuimte->getStatus());
            $nummeraanduiding->setStraat($openbareRuimte->getName());
            $nummeraanduiding->setWoonplaatsNummer($openbareRuimte->getLocalityNumber());
        }
        if(
            $nummeraanduiding->getWoonplaatsNummer() &&
            $woonplaats = $this->entityManager->getRepository('App:Woonplaats')->findOneBy(['id' => $nummeraanduiding->getWoonplaatsNummer()])
        ){
            $nummeraanduiding->setWoonplaats($woonplaats->getName());
            $nummeraanduiding->setStatusWoonplaats($woonplaats->getStatus());
        }

        $this->entityManager->persist($nummeraanduiding);
        $nummeraanduiding->setId($nummeraanduidingArray['Objecten:identificatie']['#']);
        $this->entityManager->persist($nummeraanduiding);
        $this->entityManager->flush();
        $this->entityManager->clear();
        $nummeraanduiding = null;
        $nummeraanduidingArray = [];
        $woonplaats = null;
        gc_collect_cycles();
    }

    public function loadNummerObjectenPerFile(string $filename): void
    {
        $data = $this->loadXmlFile($filename);
        $this->io->text("Used {$this->convert(memory_get_usage())} of memory");
        $this->io->createProgressBar(count($data['sl:standBestand']['sl:stand']));
        $this->io->progressStart(count($data['sl:standBestand']['sl:stand']));
        foreach($data['sl:standBestand']['sl:stand'] as $key=>$bagObject) {
//            $this->io->text($key);
            $this->loadNummerObject($bagObject["sl-bag-extract:bagObject"]['Objecten:Nummeraanduiding']);
            $this->io->progressAdvance();
        }
        $data = null;
        $this->io->progressFinish();
        unlink($filename);

        gc_collect_cycles();
        $this->entityManager->clear();
    }

    public function loadNummerObjecten()
    {
        $filenames = array_diff(scandir(dirname(__FILE__, 3).'/var/import/nummeraanduidingen'), array('..', '.'));
        foreach($filenames as $filename){
            $this->io->section("Loading $filename");
            $this->loadNummerObjectenPerFile(dirname(__FILE__, 3).'/var/import/nummeraanduidingen/'.$filename);
        }
    }

    public function loadVerblijfsObject(array $verblijfsobjectArray): void
    {
        if(!($nummeraanduiding = $this->entityManager->getRepository("App:Adres")->findOneBy(['id' => $verblijfsobjectArray['Objecten:heeftAlsHoofdadres']['Objecten-ref:NummeraanduidingRef']['#']]))){
            $nummeraanduiding = new Adres();
        } elseif ($nummeraanduiding->getType()){
            // Mutatie
        }

        $nummeraanduiding->setOppervlakte($verblijfsobjectArray['Objecten:oppervlakte'] ?? null);
        $nummeraanduiding->setStatusVerblijfsobject($verblijfsobjectArray['Objecten:status'] ?? null);
        if(isset($verblijfsobjectArray['Objecten:maaktDeelUitVan'][array_key_first($verblijfsobjectArray['Objecten:maaktDeelUitVan'])]['@domein'])){
            $typeRef = explode('.', $verblijfsobjectArray['Objecten:maaktDeelUitVan'][array_key_first($verblijfsobjectArray['Objecten:maaktDeelUitVan'])]['@domein']);
            $nummeraanduiding->setType(strtolower(end($typeRef)));
        }

        $this->entityManager->persist($nummeraanduiding);
        $this->entityManager->flush();
        $this->entityManager->clear();
        $verblijfsobjectArray = [];
        $nummeraanduiding = null;
        gc_collect_cycles();
//        $this->io->text("Loaded nummeraanduiding {$nummeraanduiding->getId()}");
    }

    public function loadVerblijfsObjectenPerFile(string $filename): void
    {
        $data = $this->loadXmlFile($filename);
        $this->io->text("Used {$this->convert(memory_get_usage())} of memory");
        $this->io->createProgressBar(count($data['sl:standBestand']['sl:stand']));
        $this->io->progressStart(count($data['sl:standBestand']['sl:stand']));
        foreach($data['sl:standBestand']['sl:stand'] as $key=>$bagObject) {
//            $this->io->text($key);
            $this->loadVerblijfsObject($bagObject["sl-bag-extract:bagObject"]['Objecten:Verblijfsobject']);
            $this->io->progressAdvance();
        }
        $data = null;
        $this->io->progressFinish();
        $this->entityManager->clear();
        unlink($filename);
        gc_collect_cycles();
    }

    public function loadVerblijfsObjecten(): void
    {
        $filenames = array_diff(scandir(dirname(__FILE__, 3).'/var/import/verblijfsobjecten'), array('..', '.'));
        foreach($filenames as $filename){
            $this->io->section("Loading $filename");
            $this->loadVerblijfsObjectenPerFile(dirname(__FILE__, 3).'/var/import/verblijfsobjecten/'.$filename);
        }
    }

    public function loadOpenbareRuimte(array $object): void
    {
        if(!($openbareRuimte = $this->entityManager->getRepository('App:OpenbareRuimte')->findOneBy(['id' => $object['Objecten:identificatie']['#']]))){
            $openbareRuimte = new OpenbareRuimte();
        }
        $openbareRuimte->setName($object['Objecten:naam'] ?? null);
        $openbareRuimte->setStatus($object['Objecten:status'] ?? null);
        $openbareRuimte->setLocalityNumber( $object['Objecten:ligtIn']['Objecten-ref:WoonplaatsRef']['#'] ?? null);

        $this->entityManager->persist($openbareRuimte);
        $openbareRuimte->setId($object['Objecten:identificatie']['#']);

        $this->entityManager->persist($openbareRuimte);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $openbareRuimte = null;
        $object = null;
        gc_collect_cycles();
    }

    public function loadOpenbareRuimtesPerFile(string $filename): void
    {
        $data = $this->loadXmlFile($filename);
        $this->io->text("Used {$this->convert(memory_get_usage())} of memory");
        $this->io->createProgressBar(count($data['sl:standBestand']['sl:stand']));
        $this->io->progressStart(count($data['sl:standBestand']['sl:stand']));
        foreach($data['sl:standBestand']['sl:stand'] as $key=>$bagObject) {
//            $this->io->text($key);
            $this->loadOpenbareRuimte($bagObject["sl-bag-extract:bagObject"]['Objecten:OpenbareRuimte']);
            $this->io->progressAdvance();
        }
        gc_collect_cycles();
        unlink($filename);
        $this->io->progressFinish();
    }

    public function loadOpenbareRuimtes(): void
    {
        $filenames = array_diff(scandir(dirname(__FILE__, 3).'/var/import/openbareruimtes'), array('..', '.'));
        foreach($filenames as $filename){
            $this->io->section("Loading $filename");
            $this->loadOpenbareRuimtesPerFile(dirname(__FILE__, 3).'/var/import/openbareruimtes/'.$filename);
        }
    }

    public function loadWoonplaats(array $object): void
    {
        if(!($woonplaats = $this->entityManager->getRepository('App:Woonplaats')->findOneBy(['id' => $object['Objecten:identificatie']['#']]))){
            $woonplaats = new Woonplaats();
        }
        $woonplaats->setName($object['Objecten:naam']);
        $woonplaats->setStatus($object['Objecten:status']);

        $this->entityManager->persist($woonplaats);

        $woonplaats->setId($object['Objecten:identificatie']['#']);
        $this->entityManager->persist($woonplaats);
        $this->entityManager->flush();
        $this->entityManager->clear();
        $woonplaats = null;
        $object = null;
        gc_collect_cycles();
    }

    public function loadWoonplaatsenPerFile(string $filename): void
    {
        $data = $this->loadXmlFile($filename);
        $this->io->text("Used {$this->convert(memory_get_usage())} of memory");
        $this->io->createProgressBar(count($data['sl:standBestand']['sl:stand']));
        $this->io->progressStart(count($data['sl:standBestand']['sl:stand']));
        foreach($data['sl:standBestand']['sl:stand'] as $key=>$bagObject) {
//            $this->io->text($key);
            $this->loadWoonplaats($bagObject["sl-bag-extract:bagObject"]['Objecten:Woonplaats']);
            $this->io->progressAdvance();
        }
        unlink($filename);
        gc_collect_cycles();
        $this->io->progressFinish();
    }

    public function loadWoonplaatsen(): void
    {
        $filenames = array_diff(scandir(dirname(__FILE__, 3).'/var/import/woonplaatsen'), array('..', '.'));
        foreach($filenames as $filename){
            $this->io->section("Loading $filename");
            $this->loadWoonplaatsenPerFile(dirname(__FILE__, 3).'/var/import/woonplaatsen/'.$filename);
        }
    }

    public function createFolders(bool $extract = false): void
    {
        if(!file_exists(dirname(__FILE__, 3).'/var/import/')){
            mkdir(dirname(__FILE__, 3).'/var/import/');
        }
        if(!file_exists(dirname(__FILE__, 3).'/var/import/zip/')){
            mkdir(dirname(__FILE__, 3).'/var/import/zip');
        }
        if(!file_exists(dirname(__FILE__, 3).'/var/import/zip/extract/' && $extract)){
            mkdir(dirname(__FILE__, 3).'/var/import/zip/extract');
        }
        if(!file_exists(dirname(__FILE__, 3).'/var/import/zip/mutaties/')){
            mkdir(dirname(__FILE__, 3).'/var/import/zip/mutaties');
        }
        if(!file_exists(dirname(__FILE__, 3).'/var/import/woonplaatsen/nummeraanduidingen/' && $extract)){
            mkdir(dirname(__FILE__, 3).'/var/import/nummeraanduidingen/');
        }
        if(!file_exists(dirname(__FILE__, 3).'/var/import/openbareruimtes/' && $extract)){
            mkdir(dirname(__FILE__, 3).'/var/import/openbareruimtes/');
        }
        if(!file_exists(dirname(__FILE__, 3).'/var/import/verblijfsobjecten/' && $extract)){
            mkdir(dirname(__FILE__, 3).'/var/import/verblijfsobjecten/');
        }
        if(!file_exists(dirname(__FILE__, 3).'/var/import/woonplaatsen/' && $extract)){
            mkdir(dirname(__FILE__, 3).'/var/import/woonplaatsen/');
        }
        if(!file_exists(dirname(__FILE__, 3).'/var/import/mutaties/')){
            mkdir(dirname(__FILE__, 3).'/var/import/mutaties/');
        }
    }

    public function setLoadingStatus(): bool
    {
        $item = $this->cache->getItem('status');
        if($item->isHit() && $item->get() == 'loading'){
            return false;
        } else {
            $item->set('loading');
        }
        $this->cache->save($item);
        return true;
    }

    public function unsetLoadingStatus(): void
    {
        $this->cache->deleteItem('status');
    }
}
