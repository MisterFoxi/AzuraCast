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

        $songIntros = [
            'Coming up next on {{station_name}}: {{artist}} with {{song}}. You\'re going to love this one.',
            'This is {{dj_name}}, and next up we have {{artist}} performing {{song}}. Sit back and enjoy.',
            'You\'re tuned into {{station_name}}. Get ready for {{song}} by {{artist}} — here it is.',
            'Right now on {{station_name}}, it\'s {{artist}} with {{song}}. Turn it up!',
            'I\'m {{dj_name}}, and I\'ve got something special: {{artist}} bringing you {{song}}.',
        ];

        $jokes = [
            'Why did the radio DJ bring a ladder to work? Because they heard the ratings were going through the roof!',
            'I told my microphone a joke… it didn\'t laugh, but the equalizer was cracking up.',
            'What\'s a DJ\'s favorite kind of music? Whatever keeps the listeners from changing the station!',
            'Why don\'t secrets last long at a radio station? Because everything eventually goes on air.',
            'I asked the playlist for advice. It said: "Just keep spinning."',
        ];

        $encouragements = [
            'Wherever you are today, remember you are valued — keep your head up and keep going.',
            'A small act of kindness can change someone\'s whole day. Be that someone.',
            'You\'ve made it through harder days than this. Take a breath — you\'ve got this.',
            'Progress counts, even when it\'s quiet. Celebrate the little wins today.',
            'You\'re listening to {{station_name}} — thanks for being part of this community.',
        ];

        foreach ($songIntros as $text) {
            $content = $this->makeContent($station, AiDjContent::TYPE_SONG_INTRO_TEMPLATE, $text);
            $manager->persist($content);
            $dj->addContent($content);
        }

        foreach ($jokes as $text) {
            $content = $this->makeContent($station, AiDjContent::TYPE_JOKE, $text);
            $manager->persist($content);
            $dj->addContent($content);
        }

        foreach ($encouragements as $text) {
            $content = $this->makeContent($station, AiDjContent::TYPE_ENCOURAGEMENT, $text);
            $manager->persist($content);
            $dj->addContent($content);
        }

        $manager->persist($dj);
        $manager->persist($schedule);
        $manager->flush();

        $this->addReference('ai_dj', $dj);
    }

    private function makeContent(Station $station, string $type, string $text): AiDjContent
    {
        $content = new AiDjContent($station);
        $content->type = $type;
        $content->content = $text;
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
