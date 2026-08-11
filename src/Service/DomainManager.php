<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class DomainManager implements DomainInterface
{
    /* top-level-domains which are known to have multiple parts */
    private const array TLD = [
        'ai'  => ['com','net','off','org'],
        'am'  => ['radio'],
        'at'  => ['ac','co','gv','or'],
        'au'  => ['com','net','org','edu','gov','asn','id'],
        'az'  => ['com','net','org'],
        'bd'  => ['com','net','org','gov','mil','ac'],
        'br'  => ['com','net','org','gov','mil','eco','emp','g12','ind','inf','rec','tur','tv','edu','far','gov','gru','jor','leg','lec','med','nom','not','ppg','pro','psi','pub','slg','srv','tec','tmp','vip','vlog','wiki','zlg'],
        'by'  => ['com','net','org','gov','mil','of'],
        'ca'  => ['ab','bc','mb','nb','nf','nl','ns','nt','nu','on','pe','qc','sk','yk'],
        'cc'  => [],
        'cn'  => ['com','net','org','gov','edu','ac','bj','sh','tj','cq','he','sx','nm','ln','jl','hl','js','zj','ah','fj','jx','sd','ha','hb','hn','gd','gx','hi','sc','gz','yn','sn','gs','qh','nx','xj','tw','hk','mo'],
        'co'  => ['com','net','org','gov','mil','edu','arts','firm','info','int','nom','rec','web'],
        'com' => ['br','cn','co','de','eu','gr','it','jpn','mex','ru','sa','uk','us','za','au','bh','bo','cn','ec','eg','gt','hk','hn','il','in','jp','kr','kw','lb','lv','my','mx','ng','ni','np','pe','pf','pg','ph','pk','pl','pr','py','sa','sg','sv','tr','tw','ua','uy','ve','vn','ye'],
        'de'  => ['com'],
        'dk'  => ['co'],
        'ec'  => ['com','net','org','gov','mil','edu','fin','med','pro'],
        'ee'  => ['com','org','pri'],
        'eg'  => ['com','net','org','gov','edu','mil'],
        'es'  => ['com','nom','org','edu','gob'],
        'eu'  => [],
        'fi'  => ['aland'],
        'fm'  => ['radio'],
        'fr'  => ['com','nom','tm','asso','gouv','pol'],
        'ge'  => ['com','net','org','edu','gov','mil'],
        'gg'  => ['co','net','org'],
        'gr'  => ['com','net','org','gov','edu','mil'],
        'hk'  => ['com','net','org','gov','edu','idv'],
        'hu'  => ['co','2000','privat','sport','tm','erotica','sex','video','info','org','net','gov','edu','mil','press','biz'],
        'id'  => ['ac','biz','co','desa','go','mil','my','net','or','sch','web'],
        'ie'  => ['gov'],
        'il'  => ['ac','co','gov','idf','k12','muni','net','org'],
        'in'  => ['co','firm','gen','ind','net','org','ac','edu','res','gov','mil'],
        'iq'  => ['com','net','org','gov','edu','mil'],
        'ir'  => ['ac','co','gov','id','net','org','sch'],
        'is'  => ['net','com','org','edu','gov','int'],
        'it'  => ['ab','ag','al','an','ao','ap','aq','ar','at','av','ba','bg','bi','bl','bn','bo','br','bs','bt','bz','ca','cb','ce','ch','cl','cn','co','cr','cs','ct','cz','en','fc','fe','fg','fi','fm','fr','ge','go','gr','im','is','kr','lc','le','li','lo','lt','lu','mb','mc','me','mi','mn','mo','ms','mt','na','no','nu','or','pa','pc','pd','pe','pg','pi','pn','po','pr','pt','pu','pv','pz','re','rg','ri','rm','rn','ro','sa','si','so','sp','sr','ss','su','sv','ta','te','tn','to','tp','tr','ts','tv','ud','va','vb','vc','ve','vi','vr','vt','vv','edu','gov','abruzzo','basilicata','calabria','campania','emilia-romagna','friuli-ve-giulia','lazio','liguria','lombardia','marche','molise','piemonte','puglia','sardegna','sicilia','toscana','trentino-a-adige','umbria','valle-aosta','veneto'],
        'je'  => ['co','net','org'],
        'jo'  => ['com','net','org','gov','edu','mil','sch'],
        'jp'  => ['ac','ad','co','ed','go','gr','lg','ne','or'],
        'ke'  => ['co','ne','or','ac','go','me','mobi','info','sc','pro'],
        'kg'  => ['com','net','org','gov','mil','edu'],
        'kr'  => ['ac','co','go','hs','kg','mil','ms','ne','or','pe','re','seoul','busan','daegu','incheon','gwangju','daejeon','ulsan','gyeonggi','gangwon','chungbuk','chungnam','jeonbuk','jeonnam','gyeongbuk','gyeongnam','jeju','sejong'],
        'kz'  => ['com','net','org','edu','gov','mil'],
        'li'  => [],
        'lt'  => ['gov'],
        'lv'  => ['com','net','org','edu','gov','mil','id','asn','conf'],
        'ly'  => ['com','net','org','gov','edu','sch','med','id'],
        'ma'  => ['co','net','org','gov','press','ac'],
        'mk'  => ['com','net','org','edu','gov','inf','name','pro'],
        'mx'  => ['com','net','org','gov','edu','mil'],
        'my'  => ['com','net','org','gov','edu','mil','name'],
        'na'  => ['com','net','org','alt','edu','gov','mil','pro'],
        'net' => ['gb','hu','in','jp','se','uk','cn','nz'],
        'ng'  => ['com','net','org','gov','edu','mil','sch','name','gov'],
        'ni'  => ['ac','co','com','edu','gob','mil','net','nom','org'],
        'nl'  => ['bv','co'],
        'no'  => ['fhs','folkebibl','kommune','mil','stat','priv','vgs','dep','kommune'],
        'nz'  => ['co','net','org','ac','geek','gen','maori','school','parliament','govt','health','mil','crii','archie','geek','govt','health','maori','school'],
        'om'  => ['com','net','org','gov','edu','med','mil','sch'],
        'org' => ['ae','us','lu'],
        'pe'  => ['com','net','org','gob','edu','mil','nom'],
        'ph'  => ['com','net','org','gov','edu','mil'],
        'pk'  => ['com','net','org','fam','biz','edu','gov','web'],
        'pl'  => ['com','net','org','aid','agro','atm','auto','biz','edu','gmina','gsm','info','mail','miasta','media','mil','ngo','nom','pc','powiat','priv','realestate','rel','sex','shop','sklep','sos','szkola','targi','tm','tourism','travel','turystyka','gov','ap','augov','bedzin','bialystok','bielawa','bierun','boleslawiec','bydgoszcz','bytom','cieszyn','czeladz','czest','dlugoleka','elblag','elk','glogow','gniezno','gorlice','gorzow','grodzisk','grudziadz','ilk','jaworzno','jelenia-gora','jgora','kalisz','kazimierz-dolny','karpacz','kartuzy','kaszuby','katowice','kepno','ketrzyn','klodzko','kobierzyce','kolobrzeg','konin','konskowola','krapkowice','krakow','krasnik','krasno','krosniewice','kutno','lapy','lebork','legnica','lezajsk','limanowa','lomza','lowicz','lubin','lukow','malbork','malopolska','mazowsze','mazury','mielec','milicz','mielno','mragowo','naklo','nowaruda','nysa','olawa','olecko','olkusz','olsztyn','opoczno','opole','ostrowiec','ostroleka','ostrowwlkp','pila','pisz','podhale','podlasie','polkowice','pomorze','pomorse','prochowice','pruszkow','przeworsk','pulawy','rabka','rawa-maz','rybnik','rzeszow','sanok','sejny','siedlce','slask','slupsk','sosnowiec','stalowa-wola','skoczow','starachowice','stargard','suwalki','swidnica','swiebodzin','swinoujscie','szczecin','szczytno','tarnobrzeg','tgory','turek','tychy','ustka','walbrzych','warmia','warszawa','waw','wegrow','wielun','wlocl','wloclawek','wodzislaw','wolomin','wroclaw','zachpomor','zagan','zarow','zgora','zgorzelec','plug'],
        'pr'  => ['ac','co','edu','gov','info','island','pro','net','org'],
        'pt'  => ['com','net','org','gov','edu','int','publ'],
        'py'  => ['com','net','org','gov','edu','mil','co'],
        'qa'  => ['com','net','org','gov','edu','mil','sch','name'],
        'ro'  => ['com','net','org','nom','rec','info','arts','com','firm','tm','www','store','nt','ngo','pro','tm','com','arts','rec','store','info','nom','nt','org','shop','firm','www','rest','travel','transport','tourism','press','media','medical','med','law','jobs','inst','individual','insinfo','guru','fit','engineering','expert','energy','economy','dot','dog','dev','design','dem','dental','craft','corp','consulting','construction','company','com','club','cloud','coach','city','cinema','church','chat','casino','cars','care','cards','broke','blog','bio','bid','band','auto','audio','attorney','apartments','app','art','archi','architects','arena','architects','associates','attorney','auction','auto','baby','band','bank','bar','bargains','beer','berlin','best','bet','bid','bike','bingo','bio','black','blog','blue','boats','bond','boo','book','boutique','build','builders','business','buzz','cab','cafe','call','cam','camp','capital','care','careers','cars','cash','casino','catering','center','ceo','ceramics','cfd','ch','chat','church','city','claims','cleaning','click','clinic','clothing','cloud','club','coach','codes','coffee','college','community','company','computer','condos','construction','consulting','contact','cooking','cool','country','courses','cpa','craft','credit','creditcard','cricket','cruise','cuisinella','cymru','dabur','dance','date','dating','deals','degree','delivery','democrat','dental','design','dev','diamonds','diet','digital','direct','directory','discount','dog','domains','doos','download','ec','edu','education','energy','engineering','enterprises','equipment','estate','events','exchange','expert','exposed','express','fail','faith','family','fan','farm','fashion','film','finance','financial','fish','fit','fitness','flights','florist','flowers','football','forex','forsale','foundation','fun','fund','furniture','futbol','fyi','gal','gallery','game','garden','gift','gifts','gives','glass','global','gold','golf','graphics','gratis','green','gripe','group','guru','health','healthcare','help','helsinki','here','hiphop','hiv','holdings','holiday','homes','horse','host','hosting','house','how','immo','immobilien','in','industries','info','ink','institute','insure','international','investments','irish','jewelry','kaufen','kids','kim','kitchen','kiwi','kred','land','law','lawyer','legal','lgbt','lifestyle','lighting','limited','limo','link','live','loan','loans','lol','london','love','ltd','ltda','luxury','maison','management','market','marketing','markets','media','memorial','men','menu','miami','mobi','moda','moe','mom','money','monster','mortgage','movie','nagoya','name','navy','net','network','news','ngo','ninja','nyc','observer','okinawa','one','ong','onl','online','ooo','org','organic','osaka','paris','partners','parts','party','photo','photography','photos','pics','pictures','pink','pizza','place','plumbing','plus','poker','porn','press','pro','productions','properties','property','pub','qpon','realtor','realty','recipes','red','rehab','reise','reisen','rent','rentals','repair','report','rest','restaurant','review','reviews','rich','rip','rocks','rodeo','run','saarland','sale','salon','sarl','save','saxo','school','schule','science','services','sex','sexy','sg','shop','shopping','show','singles','site','ski','soccer','social','software','solar','solutions','space','store','stream','studio','study','style','supplies','supply','support','surgery','systems','tax','taxi','team','tech','technology','tennis','thai','tips','tires','tirol','today','tokyo','tools','top','tour','tours','town','toys','trade','trading','training','travel','tube','university','uno','vacations','vegas','ventures','vet','viajes','video','villas','vin','vision','vlaanderen','vodka','vote','voting','voto','voyage','wales','watch','webcam','website','wedding','wien','wiki','win','wine','work','works','world','wtf','xxx','xyz','yoga','yokohama','zone'],
        'ru'  => ['ac','com','edu','int','net','org','pp','adygeya','altai','amur','arkhangelsk','astrakhan','bashkiria','belgorod','bir','bryansk','buryatia','cbg','chel','chelyabinsk','chita','chukotka','chuvashia','dagestan','dudinka','e-burg','grozny','irkutsk','ivanovo','izhevsk','jar','joshkar-ola','kalmykia','kaluga','kamchatka','karelia','kazan','kchr','kemerovo','khabarovsk','khakassia','khv','kirov','koenigsberg','komi','kostroma','krasnodar','krasnoyarsk','kuban','kurgan','kursk','lipetsk','magadan','mari','mari-el','marine','mil','mordovia','mosreg','msk','murmansk','nalchik','nnov','nov','novosibirsk','nsk','omsk','orenburg','oryol','palana','penza','perm','ptz','rnd','ryazan','sakhalin','samara','saratov','simbirsk','smolensk','spb','stavropol','stv','surgut','tambov','tatarstan','tom','tomsk','tsaritsyn','tsk','tula','tuva','tver','tyumen','udm','udmurtia','ulan-ude','vladikavkaz','vladimir','vladivostok','volgograd','vologda','voronezh','vrn','vyatka','yakutia','yamal','yaroslavl','yevrey'],
        'sa'  => ['com','net','org','gov','med','pub','edu','sch'],
        'sb'  => ['com','net','org','edu','gov'],
        'sc'  => ['com','net','org','gov','edu'],
        'se'  => ['a','ac','b','bd','brand','c','d','e','f','fh','fhsk','fhv','g','h','i','k','komforb','kommunal','komvux','kunskapsforb','l','lanbib','m','n','naturbruksgymn','o','org','p','parti','pp','press','r','s','t','tm','u','v','w','x','y','z'],
        'sg'  => ['com','net','org','gov','edu','per'],
        'sh'  => ['com','net','org','gov','mil','edu'],
        'sk'  => ['co','com','edu','gov','mil','net','org','nfo'],
        'st'  => ['co','com','consulado','edu','embaixada','gov','mil','net','org','principe','saotome','store'],
        'su'  => ['abkhazia','adygeya','ak', 'altai','amur','arkhangelsk','astrakhan','bashkiria','belgorod','bir','bryansk','buryatia','cbg','chel','chelyabinsk','chita','chukotka','chuvashia','dagestan','dudinka','e-burg','grozny','irkutsk','ivanovo','izhevsk','jar','joshkar-ola','kalmykia','kaluga','kamchatka','karelia','kazan','kchr','kemerovo','khabarovsk','khakassia','khv','kirov','koenigsberg','komi','kostroma','krasnodar','krasnoyarsk','kuban','kurgan','kursk','lipetsk','magadan','mari','mari-el','marine','mil','mordovia','mosreg','msk','murmansk','nalchik','nnov','nov','novosibirsk','nsk','omsk','orenburg','oryol','palana','penza','perm','ptz','rnd','ryazan','sakhalin','samara','saratov','simbirsk','smolensk','spb','stavropol','stv','surgut','tambov','tatarstan','tom','tomsk','tsaritsyn','tsk','tula','tuva','tver','tyumen','udm','udmurtia','ulan-ude','vladikavkaz','vladimir','vladivostok','volgograd','vologda','voronezh','vrn','vyatka','yakutia','yamal','yaroslavl','yevrey','com','net','org','gov','pp','edu'],
        'sv'  => ['com','edu','gob','org','red'],
        'sy'  => ['com','net','org','gov','edu','mil','name'],
        'th'  => ['ac','co','go','in','mi','net','or'],
        'tj'  => ['ac','biz','co','com','edu','gov','go','info','int','mil','name','net','nic','nom','org','pro','test','web'],
        'tn'  => ['agrinet','com','defense','edunet','ens','fin','gov','ind','info','intl','min','nat','net','org','perso','rnrt','rns','rnu','tourism','turen'],
        'tr'  => ['com','net','org','gov','biz','info','mil','edu','tv','bbs','k12','pol','bel','dr','gen','av','bbs','k12','name','tel','nc','web','tsk','bel','pol','edu'],
        'tw'  => ['com','net','org','edu','gov','mil','idv','game','ebiz','club','gnu'],
        'ua'  => ['com','net','org','edu','gov','in','at','cn','crimea','dn','dnepropetrovsk','donetsk','dp','if','ivano-frankivsk','kh','kharkov','kherson','khmelnitskiy','kiev','kirovograd','km','kr','ks','kv','lg','lt','lugansk','lutsk','lv','lviv','mk','mk.ua','mykolaiv','net','nikolaev','od','odessa','pl','poltava','rovno','rv','sebastopol','sm','sumy','te','ternopil','uz','uzhgorod','vinnica','vn','volyn','yalta','zaporizhzhe','zhitomir','zp','zt'],
        'uk'  => ['co','me','org','ltd','plc','net','sch','ac','gov','nhs','police','mod','nhs','parliament'],
        'us'  => ['ak','al','ar','as','az','ca','co','ct','dc','de','fl','ga','gu','hi','ia','id','il','in','ks','ky','la','ma','md','me','mi','mn','mo','ms','mt','nc','nd','ne','nh','nj','nm','nv','ny','oh','ok','or','pa','pr','ri','sc','sd','tn','tx','ut','vi','vt','va','wa','wi','wv','wy','dni','fed','isa','kids','nsn'],
        'uy'  => ['com','net','org','gub','mil','edu'],
        've'  => ['co','com','edu','gob','info','net','org','web'],
        'vn'  => ['com','net','org','edu','gov','int','ac','biz','info','name','pro','health'],
        'yu'  => ['ac','co','edu','gov','org'],
        'za'  => ['ac','alt','co','edu','gov','law','mil','net','ngo','nom','org','school','tm','web'],
    ];

    private bool $subdomainRedirect;
    private string $authSubdomain;

    public function __construct(
        #[Autowire('%app.subdomain_redirect%')] bool $subdomainRedirect,
        #[Autowire('%app.auth_subdomain%')] string   $authSubdomain,
    ) {
        $this->subdomainRedirect = $subdomainRedirect;
        $this->authSubdomain = $authSubdomain;
    }

    /** IE: "auth.example.com" or null if not using a separate subdomain
     * @return ?string Returns auth subdomain if configured, otherwise null */
    public function getAuthSubdomain(): ?string
    {
        if ($this->authBase()) {
            return $this->authSubdomain;
        }
        return null;
    }

    /** check if given url is an acceptable url for redirection
     * @param string $url Where we are thinking of sending the user
     * @return bool Returns true if it is acceptable to send the user there */
    public function validReturn(string $url): bool
    {
        /* ensure url is valid and, when using an auth subdomain,
         * that the url host matches the base domain */
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        if ($this->authBase()) {
            $host = parse_url($url, PHP_URL_HOST);
            if ($host === null || $host === false || $host === '') {
                return false;
            }
            /* do not send the user to another domain */
            return $this->matchesAuth($host);
        }

        return true;
    }

    /** check if host-base matches auth-base
     * @param string $host
     * @return bool returns true if and only if host matches base domain of auth */
    public function matchesAuth(string $host): bool
    {
        $hostBase = $this->baseDomain($host);
        $authBase = $this->baseDomain($this->authSubdomain);
        return $this->subdomainRedirect && $this->authSubdomain &&
            $authBase && $authBase === $hostBase;
    }

    /** IE: "example.com" if central auth is something like "auth.example.com"
     * @return string|null returns base domain if we are doing central auth */
    public function authBase(): ?string
    {
        if ($this->subdomainRedirect && $this->authSubdomain && $this->baseDomain($this->authSubdomain)) {
            return $this->baseDomain($this->authSubdomain);
        }
        return null;
    }

    /** this lets us determine the base domain of the given ip, localhost, or domain
     * "service.example.co.uk" into "example.co.uk" and "service.example.com" into "example.com"
     * things like "localhost" and "8.8.8.8" will return null
     * @param string $host ip, localhost, or domain with zero or more subdomains
     * @return ?string returns null if host is ip or localhost otherwise domain with all subdomains removed */
    private function baseDomain(string $host): ?string
    {
        /* if host is an ip address (or localhost), leave it as is */
        if (filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost') {
            return null;
        }

        $parts = explode('.', strtolower($host));
        $keep = $this->baseLength($parts);
        $parts = array_slice($parts, -$keep);
        return implode('.', $parts);
    }

    /** IE: ["www", "example", "com"] or ["www", "example", "co", "uk"]
     * @param string[] $parts pieces of a domain split by "." dot
     * @return int typically 2 but sometimes 3 */
    private function baseLength(array $parts): int
    {
        $length = count($parts);
        $baseLength = min(2, $length);
        /* check if host should retain 3 parts, due to TLD */
        if (count($parts) > 2 && isset(self::TLD[$parts[$length - 1]]) &&
            in_array($parts[$length - 2], self::TLD[$parts[$length - 1]], true)
        ) {
            $baseLength = min(3, $length);
        }
        return $baseLength;
    }
}
