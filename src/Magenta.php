<?php

namespace XmlTvA1;

use CurlHandle;
use Psr\SimpleCache\CacheInterface;
use XmlTv\Tv;
use XmlTv\Tv\Channel;
use XmlTv\Tv\Programme;
use XmlTv\Tv\Elements;
use XmlTv\Tv\Source;

class Magenta implements Source
{
    private const LANG = 'de';
    private const CURL_USER_AGENT = 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/114.0';

    private const SOURCE_INFO_URL = 'https://tv.magenta.at/epg';
    private const SOURCE_INFO_NAME = 'Magenta';

    private const SOURCE = 'https://tv.magenta.at/epg';
    private const SOURCE_CHANNELS = 'https://tv-at-prod.yo-digital.com/at-bifrost/epg/channel';
    private const SOURCE_CHANNEL_INFO = 'https://tv-at-prod.yo-digital.com/at-bifrost/epg/channel/schedules/v2';
    private const SOURCE_CHANNELS_QUERY = [
        'app_language' => 'de',
        'natco_code' => 'at',
    ];

    private Tv $tv;
    private CurlHandle $ch;
    private CacheInterface $cache;
    private bool $debug;
    private bool $mapChannelIdsToA1;
    private array $configuration = [];

    public function __construct(CacheInterface $cache, bool $debug = false, bool $mapChannelIdsToA1 = false)
    {
        $this->tv = new Tv(self::SOURCE_INFO_URL, self::SOURCE_INFO_NAME);
        $this->ch = curl_init();

        $this->cache = $cache;
        $this->debug = $debug;
        $this->mapChannelIdsToA1 = $mapChannelIdsToA1;
    }

    public function __destruct()
    {
        curl_close($this->ch);
    }

    public function get(): Tv
    {
        $this->load();

        return $this->tv;
    }

    private function load(): void
    {
        $this->debug('Loading configuration ...');
        $this->loadConfiguration();

        $this->debug('Loading channels ...');
        $this->loadChannels();

        $this->debug('Loading programmes ...');
        foreach ($this->tv->getChannels() as $channel) {
            if (str_starts_with($channel->getDisplayName()[0]->value, 'Sky')) {
                $this->debug('Skipping channel "' . $channel->getDisplayName()[0]->value . '"');
                continue;
            }
            $this->debug('Loading programme info for "' . $channel->getDisplayName()[0]->value . '"');
            $this->loadProgramme($channel->id);
        }

        if ($this->mapChannelIdsToA1) {
            $this->debug('Mapping channel IDs to A1 ...');
            $channelIdMap = [];
            foreach ($this->tv->getChannels() as $channel) {
                $channelId = $this->mapChannelNameToA1TvgId($channel->getDisplayName()[0]->value) ?? $channel->id;
                $channelIdMap[$channel->id] = $channelId;
                $channel->id = $channelId;
            }
            foreach ($this->tv->getProgrammes() as $programme) {
                $programme->channel = $channelIdMap[$programme->channel] ?? $programme->channel;
            }
        }
    }

    private function loadConfiguration(): void
    {
        $html = $this->loadUrl(self::SOURCE);

        if (preg_match('/window\.APP_CONSTANTS = (?<config>{".*"})/m', $html, $matches)) {
            $this->configuration = json_decode($matches['config'], true);
        } else {
            throw new \RuntimeException('Could not extract API key from Magenta website');
        }
    }

    private function loadChannels(): void
    {
        $cacheKey = 'channels';

        if ($this->cache->has($cacheKey)) {
            $this->debug('Loading infos from cache "' . $cacheKey . '"');
            $rawJson = $this->cache->get($cacheKey);
        } else {
            $url = self::SOURCE_CHANNELS . '?' . http_build_query(self::SOURCE_CHANNELS_QUERY);
            $this->debug('Loading infos from URL ' . $url);
            $rawJson = $this->loadUrl($url);
        }

        $channels = json_decode($rawJson, true);
        if (is_array($channels) && array_key_exists('channels', $channels)) {
            $this->cache->set($cacheKey, $rawJson);
            $this->addChannels($channels['channels']);
        } else {
            throw new \RuntimeException('Could not decode JSON or invalid response.');
        }
    }

    private function loadProgramme($channelId): void
    {
        $today = date('Y-m-d', time());
        $tomorrow = date("Y-m-d", strtotime('tomorrow'));

        foreach ([$today, $tomorrow] as $date) {
            for ($offset = 0; $offset <= 21; $offset += 3) {
                $cacheKey = sprintf('date.%s.%s.%s', preg_replace('/[^0-9]/', '', $date), $offset, $channelId);

                if ($this->cache->has($cacheKey)) {
                    $this->debug('Loading infos from cache "' . $cacheKey . '"');
                    $rawJson = $this->cache->get($cacheKey);
                } else {
                    $channelInfoUrl = $this->getChannelInfoUrl($channelId, $date, $offset);
                    $this->debug('Loading infos from URL ' . $channelInfoUrl);
                    $rawJson = $this->loadUrl($channelInfoUrl);
                }

                $programmes = json_decode($rawJson, true);
                if (is_array($programmes) && array_key_exists('channels', $programmes)) {
                    $this->cache->set($cacheKey, $rawJson);
                    if (count($programmes['channels']) === 0) return;
                    $this->addProgrammes($programmes['channels'][$channelId], $channelId);
                } else {
                    throw new \RuntimeException('Could not decode JSON or invalid response.');
                }
            }
        }
    }

    private function loadUrl(string $url): string
    {
        curl_setopt($this->ch, CURLOPT_URL, $url);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->ch, CURLOPT_USERAGENT, self::CURL_USER_AGENT);
        curl_setopt($this->ch, CURLOPT_ENCODING, '');
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, [
            'app_key: ' . $this->configuration['CMS_CONFIGURATION_API_KEY'] ?? '',
            'app_version: ' . $this->configuration['APP_VERSION'] ?? '',
            'Device-Id: ' . $this->configuration['DEVICE_ID'] ?? '',
            'X-User-Agent: web|web|Firefox-114|02.0.660|1',
        ]);

        $result = curl_exec($this->ch);
        $error = curl_errno($this->ch);

        if (!$error && $result) {
            return $result;
        } else {
            throw new \RuntimeException('cURL request failed.');
        }
    }

    private function addChannels($data): void
    {
        foreach ($data as $channelInfo) {
            $channel = new Channel($channelInfo['station_id']);
            $channel->addIcon(new Elements\Icon($channelInfo['channel_logo']));
            $channel->addDisplayName(new Elements\DisplayName($channelInfo['title']));
            $this->tv->addChannel($channel);
        }
    }

    private function addProgrammes($data, $channelId): void
    {
        foreach ($data as $programmeInfo) {
            if (empty($programmeInfo)) continue;

            $programme = new Programme(
                $channelId,
                date(Tv::DATE_FORMAT, strtotime($programmeInfo['start_time'])),
                date(Tv::DATE_FORMAT, strtotime($programmeInfo['end_time']))
            );
            $programme->addTitle(new Elements\Title($programmeInfo['description'], self::LANG));
            if ($programmeInfo['release_year']) {
                $programme->date = new Elements\Date($programmeInfo['release_year']);
            }
            foreach ($programmeInfo['genres'] ?? [] as $genre) {
                $programme->addCategory(new Elements\Category($genre['name'], self::LANG));
            }
            $this->tv->addProgramme($programme);
        }
    }

    private function getChannelInfoUrl(string $id, string $date, int $offset): string
    {
        return self::SOURCE_CHANNEL_INFO . '?' . http_build_query([
            ...self::SOURCE_CHANNELS_QUERY,
            'date' => $date,
            'hour_offset' => (string) $offset,
            'hour_range' => '3',
            'station_ids' => $id,
        ]);
    }

    private function mapChannelNameToA1TvgId(string $channelName): ?string
    {
        $a1ChannelMap = [
            'ORF 1' => 14,
            'ORF 2 Wien' => 625,
            'CANAL+ FiRST' => 1452,
            'Servus TV' => 4119,
            'OE24.TV' => 1163,
            'RTL Austria' => 3835,
            'VOX Austria' => 3837,
            'RTLZWEI Austria' => 896,
            'NITRO Austria' => 4947,
            'Das Erste' => 1,
            'ZDF' => 642,
            'ORF III' => 611,
            '3sat' => 118,
            'arte' => 10,
            'DMAX Austria' => 665,
            'Sport1' => 12,
            'ORF Sport+' => 612,
            'SuperRTL' => 179,
            'KIKA' => 63,
            'nick' => 652,
            'Disney Channel' => 885,
            'RiC' => 7002,
            'Comedy Central' => 121,
            'Deluxe Music' => 20310,
            'N24 Doku' => 953,
            'ntv' => 7,
            'zdf_neo' => 644,
            'ZDFinfo' => 643,
            'phoenix' => 206,
            'tagesschau24' => 962,
            'one' => 1024,
            'ARD alpha' => 57,
            'Tele 5' => 7017,
            'BR Fernsehen' => 18,
            'SWR BW' => 29,
            'mdr' => 33,
            'NDR' => 19,
            'WDR' => 17,
            'hr' => 26,
            'rbb' => 35,
            'CNN international' => 2025,
            'BBC World News' => 112,
            'BBC Entertainment' => 2043,
            'Euronews' => 13,
            'Bloomberg' => 2011,
            'Al Jazeera' => 720,
            'TV5 Monde Europe' => 2559,
            'France24' => 2632,
            'ORF 2 B' => 617,
            'ORF 2 K' => 618,
            'ORF 2 N' => 619,
            'ORF 2 O' => 620,
            'ORF 2 S' => 621,
            'ORF 2 St' => 622,
            'ORF 2 T' => 623,
            'ORF 2 V' => 624,
            'ORF 2 Europe' => 641,
            'Ö3 Visual Radio' => 613,
            'RTLup Austria' => 1187,
            'TLC' => 666,
            'SR' => 31,
            '1-2-3.tv' => 1240,
            'QVC' => 7021,
            'HSE24' => 32,
            'AstroTV' => 1562,
            'Bibel TV' => 7038,
            'LAOLA1.tv' => 892,
            'Eurosport 1' => 8090,
            'Arcadia TV' => 1154,
            'Kanal 7 Avrupa' => 7014,
            'BN TV' => 20166,
            'ČT24' => 698,
            'RTL Croatia World' => 919,
            'Pro TV Int' => 20169,
            'OKTO' => 4107,
            'LT1' => 20089,
            'KT1' => 20091,
            'BTV Kärnten' => 20142,
            'Folx TV' => 1245,
            'Mühlviertel TV' => 20306,
            'Rai Uno' => 3034,
            'Rai Due' => 3023,
            'Rai Tre' => 3033,
            'Rai News 24' => 1267,
            'Rai Storia' => 1268,
            'Rai Scuola' => 1269,
            'ORF 1 HD' => 908,
            'ORF 2 Wien HD' => 638,
            'Servus TV HD' => 20130,
            'OE24.TV HD' => 1435,
            'RTL Austria HD' => 181,
            'VOX Austria HD' => 183,
            'RTLZWEI Austria HD' => 182,
            'NITRO Austria HD' => 379,
            'Das Erste HD' => 20131,
            'ZDF HD' => 646,
            'ORF III HD' => 628,
            '3sat HD' => 20197,
            'arte HD' => 20129,
            'DMAX HD' => 669,
            'Sport1 HD' => 20179,
            'ORF Sport+ HD' => 629,
            'SuperRTL HD' => 394,
            'KiKA HD' => 20203,
            'nick HD' => 655,
            'Comedy Central HD' => 911,
            'Deluxe Music HD' => 365,
            'Welt HD' => 20178,
            'ntv HD' => 395,
            'zdf_neo HD' => 648,
            'ZDFinfo HD' => 647,
            'SRF INFO HD' => 1167,
            'phoenix HD' => 20198,
            'tagesschau24 HD' => 640,
            'one HD' => 134,
            'Tele 5 HD' => 718,
            'BR Fernsehen HD' => 20199,
            'SWR BW HD' => 20302,
            'mdr HD' => 398,
            'NDR HD' => 20303,
            'WDR HD' => 20204,
            'hr HD' => 399,
            'BBC World News HD' => 910,
            'Euronews HD' => 1450,
            'ORF 2 Burgenland HD' => 630,
            'ORF 2 Kärnten HD' => 631,
            'ORF 2 Nö HD' => 632,
            'ORF 2 Oberösterreich HD' => 633,
            'ORF 2 Salzburg HD' => 634,
            'ORF 2 Steiermark HD' => 635,
            'ORF 2 Tirol HD' => 636,
            'ORF 2 Vorarlberg HD' => 637,
            'TLC HD' => 670,
            'QVC HD' => 723,
            'QVC Plus HD' => 722,
            'HSE24 HD' => 220,
            'HSE24 Extra HD' => 421,
            'LAOLA1.tv HD' => 8097,
            'K19 HD' => 1241,
            'Arcadia TV HD' => 1155,
            'OKTO HD' => 1405,
            'W24 HD' => 1152,
            'Ländle TV HD' => 1188,
            'Aichfeld TV HD' => 1184,
            'R9 Österreich HD' => 1153,
            'HT1 HD' => 1183,
            'donau_Kanal HD' => 1169,
            'FS1 HD' => 1156,
            'Sony AXN' => 847,
            'Fix & Foxi' => 3849,
            'nick junior' => 1407,
            'MTV80s A1' => 1143,
            'Animal Planet' => 668,
            'C+I' => 721,
            'Sony AXN HD' => 7072,
            'Romance TV HD' => 785,
            'Animal Planet HD' => 27054,
            'Eurosport 1 HD' => 888,
            'Heimatkanal' => 816,
            'nicktoons' => 1408,
            'ATV Avrupa' => 1195,
            'Haber Türk' => 1409,
            'Euro D' => 1200,
            'Euro Star' => 1197,
            'TV8 Int' => 1199,
            'Pink Extra' => 1215,
            'Pink Film ' => 1216,
            'Pink Folk' => 1217,
            'Pink Kids' => 1218,
            'Pink Music' => 1219,
            'Pink Plus' => 1203,
            'Pink Reality' => 1220,
            'RTL 2 Croatia' => 1221,
            'RTL Kockica' => 1222,
            'Nova TV' => 1223,
            'CMC TV' => 1224,
            'Prva Srbska TV' => 1229,
            'b92' => 1230,
            'DM SAT' => 1231,
            'Happy TV' => 1232,
            'RTRS' => 1233,
            'ELTA HD' => 1234,
            'ATV Bosnia' => 1236,
            'OTV Valentino' => 1237,
            'Federalna TV' => 1238,
            'BHT 1' => 1239,
            'RTS 1' => 1226,
            'UA Pershyj' => 1462,
            '1+1 International' => 1463,
        ];

        return array_key_exists($channelName, $a1ChannelMap) ? $a1ChannelMap[$channelName] : null;
    }

    private function debug(string $message): void
    {
        if ($this->debug) echo $message . PHP_EOL;
    }
}
