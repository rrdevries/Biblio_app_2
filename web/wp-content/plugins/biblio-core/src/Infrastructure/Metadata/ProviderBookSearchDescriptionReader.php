<?php

declare(strict_types=1);
namespace Biblio\Core\Infrastructure\Metadata;
use Biblio\Core\Application\Metadata\GlobalSearch\BookSearchDescriptionReader;
use Biblio\Core\Application\Metadata\{ProviderHttpClient,ProviderHttpRequest,ProviderHttpResultStatus,MetadataClock};
use Biblio\Core\Infrastructure\Metadata\OpenLibrary\OpenLibraryConfiguration;
use Biblio\Core\Infrastructure\Metadata\GoogleBooks\GoogleBooksConfiguration;
use Throwable;

final readonly class ProviderBookSearchDescriptionReader implements BookSearchDescriptionReader
{
    public function __construct(private ProviderHttpClient $http, private MetadataClock $clock,
        private ?OpenLibraryConfiguration $openLibrary, private ?GoogleBooksConfiguration $google) {}
    public function read(?array $source): array
    {
        $empty=['state'=>'unavailable','text'=>null,'language'=>null,'language_basis'=>null,'provenance'=>null,'truncated'=>false];
        if ($source===null) { return $empty; }
        try {
            $provider=$source['provider_key']; $id=$source['record_id']; $scope=$source['scope']; $headers=['Accept'=>'application/json'];
            if ($provider==='open_library') {
                if ($this->openLibrary===null) { return array_replace($empty,['state'=>'failure']); }
                $pattern=$scope==='work'?'~^/works/OL[0-9]+W$~D':'~^/books/OL[0-9]+M$~D';
                if (preg_match($pattern,$id)!==1) { return $empty; }
                $sourceUrl='https://openlibrary.org'.$id; $url=$sourceUrl.'.json'; $headers['User-Agent']=$this->openLibrary->userAgent();
            } elseif ($provider==='google_books' && $scope==='edition') {
                if ($this->google===null || preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$id)!==1) { return $empty; }
                $sourceUrl='https://books.google.com/books?id='.rawurlencode($id);
                $url='https://www.googleapis.com/books/v1/volumes/'.rawurlencode($id);
                if ($this->google->apiKey()!==null) { $url.='?key='.rawurlencode($this->google->apiKey()); }
            } else { return $empty; }
            $result=$this->http->get(new ProviderHttpRequest($url,$headers,6,1048576));
            if ($result->status()!==ProviderHttpResultStatus::Response) { return array_replace($empty,['state'=>'failure']); }
            $response=$result->requireResponse();
            if ($response->statusCode()===404) { return $empty; }
            if ($response->statusCode()!==200) { return array_replace($empty,['state'=>'failure']); }
            $data=json_decode($response->body(),true,64,JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($provider==='open_library' && ($data['key']??null)!==$id) || ($provider==='google_books' && ($data['id']??null)!==$id)) { return array_replace($empty,['state'=>'failure']); }
            $raw=$provider==='open_library' ? ($data['description']??null) : ($data['volumeInfo']['description']??null);
            if (is_array($raw)) { $raw=$raw['value']??null; }
            if (!is_string($raw) || !mb_check_encoding($raw,'UTF-8')) { return $empty; }
            // Plain text only. Remove script/style contents before stripping markup.
            $raw=preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is','',$raw)??'';
            $raw=preg_replace('~<br\s*/?>|</(?:p|div|li)>~i',"\n\n",$raw)??'';
            $text=trim(html_entity_decode(strip_tags($raw),ENT_QUOTES|ENT_HTML5,'UTF-8'));
            $text=preg_replace('/\n[\t ]*/',"\n",str_replace(["\r\n","\r"],"\n",$text))??'';
            $text=preg_replace('/\n{3,}/',"\n\n",$text)??'';
            if ($text==='') { return $empty; }
            $truncated=strlen($text)>32768; $text=mb_strcut($text,0,32768,'UTF-8');
            return ['state'=>'available','text'=>$text,'language'=>null,'language_basis'=>null,
                'provenance'=>['provider_key'=>$provider,'record_id'=>$id,'scope'=>$scope,'source_url'=>$sourceUrl,'retrieved_at'=>$this->clock->now()->format(DATE_ATOM)],'truncated'=>$truncated];
        } catch (Throwable) { return array_replace($empty,['state'=>'failure']); }
    }
}
