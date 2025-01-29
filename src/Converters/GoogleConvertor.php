<?php

namespace Cion\TextToSpeech\Converters;

use Cion\TextToSpeech\Contracts\Converter;
use Cion\TextToSpeech\Traits\HasLanguage;
use Cion\TextToSpeech\Traits\Sourceable;
use Cion\TextToSpeech\Traits\SSMLable;
use Cion\TextToSpeech\Traits\Storable;

use Google\Cloud\TextToSpeech\V1\AudioConfig;
use Google\Cloud\TextToSpeech\V1\AudioEncoding;
use Google\Cloud\TextToSpeech\V1\SsmlVoiceGender;
use Google\Cloud\TextToSpeech\V1\SynthesisInput;
use Google\Cloud\TextToSpeech\V1\TextToSpeechClient;
use Google\Cloud\TextToSpeech\V1\SynthesizeSpeechRequest;
use Google\Cloud\TextToSpeech\V1\SynthesizeSpeechResponse;
use Google\Cloud\TextToSpeech\V1\VoiceSelectionParams;
use Illuminate\Support\Arr;
use Svg\Tag\Text;

class GoogleConvertor implements Converter
{
    use Storable, Sourceable, HasLanguage, SSMLable;

    /**
     * Client instance of Google.
     *
     * @var TextToSpeechClient
     */
    protected $client;

    /**
     * Construct converter.
     *
     * @param TextToSpeechClient $client
     */
    public function __construct(TextToSpeechClient $client)
    {
        $this->client = $client;
    }

    /**
     * Get the Google Client.
     *
     * @return TextToSpeechClient
     */
    public function getClient(): TextToSpeechClient
    {
        return $this->client;
    }

    /**
     * Converts the text to speech.
     *
     * @param  string  $data
     * @param  array|null  $options
     * @return string|array
     */
    public function convert(string $data, array $options = null)
    {
        $text = $this->getTextFromSource($data);

        if ($this->isTextAboveLimit($text)) {
            $text = $this->getChunkText($text);
        }

        $result = $this->synthesizeSpeech($text, $options);


        if ($result instanceof SynthesizeSpeechResponse) {
            // Store audio file to disk
            return $this->store(
                $this->getTextFromSource($data),
                $this->getResultContent($result)
            );
        }

        return $this->store(
            $this->getTextFromSource($data),
            $this->mergeOutputs($result)
        );
    }

    /**
     * Request to Amazon Google to synthesize speech.
     *
     * @param  string|array  $text
     * @param  array|null  $options
     * @return SynthesizeSpeechResponse|array
     */
    protected function synthesizeSpeech($text, array $options = null)
    {

        $input = new SynthesisInput();
        if($this->textType() === 'ssml'){
            $input->setSsml($text);
        }else{
            $input->setText($text);
        }

        $voice = new VoiceSelectionParams();
        $voice->setLanguageCode($this->getLanguage());
        $voice->setSsmlGender(SsmlVoiceGender::MALE);
        $audioConfig = new AudioConfig();
        $audioConfig->setAudioEncoding(AudioEncoding::MP3);

        if (is_string($text)) {
            return $this->client->synthesizeSpeech($input, $voice, $audioConfig);
        }

        $results = [];
        if(is_array($text)){

            foreach ($text as $textItem) {
                $result = $this->client->synthesizeSpeech($input, $voice, $audioConfig);

                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Merges the output from amazon google.
     *
     * @return mixed
     */
    protected function mergeOutputs(array $results)
    {
        $mergedResult = null;
        foreach ($results as $result) {
            $mergedResult .= $this->getResultContent($result);
        }

        return $mergedResult;
    }

    /**
     * Checks the length of the text if more than 3000.
     *
     * @param  string  $text
     * @return bool
     */
    protected function isTextAboveLimit(string $text)
    {
        return strlen($text) > 2000;
    }

    /**
     * Chunk the given text into array.
     *
     * @param  string  $text
     * @param  int  $size
     * @return array
     */
    protected function getChunkText(string $text, int $size = 2000)
    {
        return explode("\n", wordwrap($text, $size));
    }

    /**
     * Get the text to speech voice ID.
     *
     * @param  array  $options
     * @return string
     */
    protected function voice($options)
    {
        $default = config('tts.services.google.voice', 'Amy');

        return Arr::get($options, 'voice', $default);
    }

    /**
     * Get the text type.
     *
     * @return string
     */
    protected function textType()
    {
        $default = (string) config('tts.text_type', 'text');

        return $this->textType ?? $default;
    }

    /**
     * Get the language.
     *
     * @return string
     */
    protected function getLanguage()
    {
        return $this->language ?? config('tts.language', 'en-US');
    }

    /**
     * Get the audio format.
     *
     * @param  array  $options
     * @return string
     */
    protected function format($options)
    {
        if ($this->hasSpeechMarks()) {
            return 'json';
        }

        $default = config('tts.output_format', 'mp3');

        return Arr::get($options, 'format', $default);
    }

    /**
     * Get the engine.
     *
     * @param  array  $options
     * @return string
     */
    protected function engine($options)
    {
        return Arr::get($options, 'engine', 'standard');
    }

    /**
     * Get the content of the result from AWS Google.
     *
     * @param  SynthesizeSpeechResponse  $result
     * @return mixed
     */
    protected function getResultContent($result)
    {
        return $result->getAudioContent();
    }

    /**
     * Determines if speech marks are set.
     *
     * @return bool
     */
    protected function hasSpeechMarks()
    {
        return ! empty($this->getSpeechMarks());
    }

    /**
     * Format the given json string into an array.
     *
     * @param  string  $json
     * @return array
     */
    protected function formatToArray($json)
    {
        $jsons = explode(PHP_EOL, $json);

        array_pop($jsons);

        return collect($jsons)->map(function ($json) {
            return json_decode($json, true);
        })->toArray();
    }


    public function mapLang()
    {
        $langs = [
            'af' => 'af-ZA-Standard-A',
            'ar' => 'ar-XA-Wavenet-D',
            'bg' => 'bg-BG-Standard-A',
            'bn' => 'bn-IN-Wavenet-C',
            'cs' => 'cs-CZ-Wavenet-A',
            'da' => 'da-DK-Neural2-D',
            'de' => 'de-DE-Neural2-A',
            'el' => 'el-GR-Wavenet-A',
            'en' => 'en-GB-News-H',
            'es' => 'es-ES-Neural2-A',
            'fa' => 'فارسی',
            'fi' => 'fi-FI-Wavenet-A',
            'fr' => 'fr-FR-Neural2-C',
            'he' => 'he-IL-Wavenet-C',
            'hi' => 'hi-IN-Neural2-D',
            'id' => 'id-ID-Wavenet-A',
            'it' => 'Italiano',
            'jp' => '日本語',
            'ko' => '한국어',
            'lv' => 'Latviešu valoda',
            'ms' => 'Bahasa Melayu',
            'nl' => 'Nederlands',
            'nb' => 'Norsk Bokmål',
            'pl' => 'Polski',
            'pt' => 'Português',
            'ru' => 'Русский',
            'sw' => 'Kiswahili',
            'sv' => 'Svenska',
            'ta' => 'தமிழ்',
            'th' => 'ไทย',
            'tr' => 'Türkçe',
            'uk' => 'Українська',
            'vi' => 'Tiếng Việt',
            'cn' => '简体中文',
            'tw' => '繁體中文'
        ];

    }


}
