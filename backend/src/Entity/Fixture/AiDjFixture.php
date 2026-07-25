<?php

declare(strict_types=1);

namespace App\Entity\Fixture;

use App\Entity\AiDj;
use App\Entity\AiDjContent;
use App\Entity\AiDjSchedule;
use App\Entity\Station;
use DateTimeImmutable;
use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Seeds one default AI DJ personality plus starter content library rows
 * so a fresh install is not a blank AI DJ page.
 *
 * Content arrays below cover every built-in category. Replace placeholder /
 * starter copy with the production seed pack when provided.
 */
final class AiDjFixture extends AbstractFixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $station = $this->getReference('station', Station::class);

        $dj = new AiDj();
        $dj->setStation($station);
        $dj->setName('Morning Host');
        $dj->setIsEnabled(true);
        $dj->setVoiceModelPath('kokoro:am_adam');
        $dj->setTalkFrequency(0.5);
        $dj->setVoiceSpeed(1.0);
        $dj->setUseBackgroundAudio(false);
        $dj->setShiftIntroTemplate(
            'Hey, this is {{dj_name}} on {{station_name}}. Welcome to the show — glad you\'re here!'
        );
        $dj->setShiftOutroTemplate(
            'This has been {{dj_name}} on {{station_name}}. Thanks for listening — stay blessed!'
        );

        $schedule = new AiDjSchedule($dj);
        $schedule->setName('Weekday mornings');
        $schedule->setStartTime(new DateTimeImmutable('06:00:00'));
        $schedule->setEndTime(new DateTimeImmutable('10:00:00'));
        $schedule->setLoopDays([1, 2, 3, 4, 5]);
        $schedule->setIsEnabled(true);
        $dj->addSchedule($schedule);

        // --- Content library (all categories). Paste production copy into these arrays. ---

        /** @var list<string> $songIntros */
        $songIntros = [
            // TODO: replace with production song intro templates
            'Coming up next on {{station_name}}: {{artist}} with {{song}}. You\'re going to love this one.',
            'This is {{dj_name}}, and next up we have {{artist}} performing {{song}}. Sit back and enjoy.',
            'You\'re tuned into {{station_name}}. Get ready for {{song}} by {{artist}} — here it is.',
            'Right now on {{station_name}}, it\'s {{artist}} with {{song}}. Turn it up!',
            'I\'m {{dj_name}}, and I\'ve got something special: {{artist}} bringing you {{song}}.',
        ];

        /** @var list<string> $postSong */
        $postSong = [
            // TODO: replace with production post-song templates
            'That was {{prev_artist}} with {{prev_song}}. Coming up next: {{next_artist}} — {{next_song}}.',
            'You just heard {{prev_song}} by {{prev_artist}} here on {{station_name}}. Stick around.',
            '{{dj_name}} here — loved that {{prev_artist}} track. Up next is {{next_song}}.',
            'From {{prev_artist}} to what\'s next: {{next_artist}} with {{next_song}} on {{station_name}}.',
            'Thanks for listening to {{prev_song}}. {{next_artist}} is right around the corner.',
        ];

        /** @var list<string> $jokes */
        $jokes = [
            // TODO: replace with production jokes
            'Why did the radio DJ bring a ladder to work? Because they heard the ratings were going through the roof!',
            'I told my microphone a joke… it didn\'t laugh, but the equalizer was cracking up.',
            'What\'s a DJ\'s favorite kind of music? Whatever keeps the listeners from changing the station!',
            'Why don\'t secrets last long at a radio station? Because everything eventually goes on air.',
            'I asked the playlist for advice. It said: "Just keep spinning."',
        ];

        /**
         * Bible verses: content = verse text, reference = citation (e.g. "John 3:16").
         *
         * @var list<array{content: string, reference: string}> $bibleVerses
         */
        $bibleVerses = [
            // TODO: replace with production bible verses
            [
                'content' => 'For God so loved the world that he gave his one and only Son, that whoever believes in him shall not perish but have eternal life.',
                'reference' => 'John 3:16',
            ],
            [
                'content' => 'I can do all this through him who gives me strength.',
                'reference' => 'Philippians 4:13',
            ],
            [
                'content' => 'Trust in the Lord with all your heart and lean not on your own understanding.',
                'reference' => 'Proverbs 3:5',
            ],
            [
                'content' => 'The Lord is my shepherd, I lack nothing.',
                'reference' => 'Psalm 23:1',
            ],
            [
                'content' => 'Be strong and courageous. Do not be afraid; do not be discouraged, for the Lord your God will be with you wherever you go.',
                'reference' => 'Joshua 1:9',
            ],
        ];

        /** @var list<string> $encouragements */
        $encouragements = [
            // TODO: replace with production encouragements
            'Wherever you are today, remember you are valued — keep your head up and keep going.',
            'A small act of kindness can change someone\'s whole day. Be that someone.',
            'You\'ve made it through harder days than this. Take a breath — you\'ve got this.',
            'Progress counts, even when it\'s quiet. Celebrate the little wins today.',
            'You\'re listening to {{station_name}} — thanks for being part of this community.',
        ];

        /** @var list<string> $inspiration */
        $inspiration = [
            // TODO: replace with production inspiration
            'Faith is taking the first step even when you don\'t see the whole staircase.',
            'Your story isn\'t over — today can be a new chapter.',
            'Light shines brightest when the night feels longest. Hold on.',
            'Hope is not wishful thinking; it\'s choosing to believe good things are still ahead.',
            'You were made for a purpose. Keep walking in it.',
        ];

        /** @var list<string> $testimonies */
        $testimonies = [
            // TODO: replace with production testimonies
            'I used to feel alone in the struggle — then I found a community that prayed with me, and everything changed.',
            'There was a season I thought I\'d never smile again. God met me right where I was.',
            'I walked into church broken and walked out knowing I was loved. That day still shapes me.',
            'Someone shared a kind word with me at exactly the right moment. Never underestimate that.',
            'My faith didn\'t remove every storm — but it taught me I never face them alone.',
        ];

        /** @var list<string> $stories */
        $stories = [
            // TODO: replace with production stories
            'A listener once called in just to say thank you — that short call reminded our whole team why we do this.',
            'Years ago a song on the air helped someone through a hard night. Music still carries hope like that.',
            'Two strangers met in our lobby and left as friends. Radio connects more than playlists.',
            'We played a dedication for a birthday miles away — the smile on that call was unforgettable.',
            'Every broadcast is a chance to speak life into someone you\'ll never meet. That\'s the quiet miracle of radio.',
        ];

        $this->persistTexts($manager, $dj, $station, AiDjContent::TYPE_SONG_INTRO_TEMPLATE, $songIntros);
        $this->persistTexts($manager, $dj, $station, AiDjContent::TYPE_POST_SONG_TEMPLATE, $postSong);
        $this->persistTexts($manager, $dj, $station, AiDjContent::TYPE_JOKE, $jokes);
        $this->persistReferenced($manager, $dj, $station, AiDjContent::TYPE_BIBLE_VERSE, $bibleVerses);
        $this->persistTexts($manager, $dj, $station, AiDjContent::TYPE_ENCOURAGEMENT, $encouragements);
        $this->persistTexts($manager, $dj, $station, AiDjContent::TYPE_INSPIRATION, $inspiration);
        $this->persistTexts($manager, $dj, $station, AiDjContent::TYPE_TESTIMONY, $testimonies);
        $this->persistTexts($manager, $dj, $station, AiDjContent::TYPE_STORY, $stories);

        $manager->persist($dj);
        $manager->persist($schedule);
        $manager->flush();

        $this->addReference('ai_dj', $dj);
    }

    /**
     * @param list<string> $texts
     */
    private function persistTexts(
        ObjectManager $manager,
        AiDj $dj,
        Station $station,
        string $type,
        array $texts
    ): void {
        foreach ($texts as $text) {
            $content = $this->makeContent($station, $type, $text);
            $manager->persist($content);
            $dj->addContent($content);
        }
    }

    /**
     * @param list<array{content: string, reference: string}> $items
     */
    private function persistReferenced(
        ObjectManager $manager,
        AiDj $dj,
        Station $station,
        string $type,
        array $items
    ): void {
        foreach ($items as $item) {
            $content = $this->makeContent($station, $type, $item['content'], $item['reference']);
            $manager->persist($content);
            $dj->addContent($content);
        }
    }

    private function makeContent(
        Station $station,
        string $type,
        string $text,
        ?string $reference = null
    ): AiDjContent {
        $content = new AiDjContent($station);
        $content->type = $type;
        $content->content = $text;
        $content->reference = $reference;
        $content->is_enabled = true;
        $content->is_global = false;

        return $content;
    }

    /**
     * @return string[]
     */
    public function getDependencies(): array
    {
        return [
            StationFixture::class,
        ];
    }
}
