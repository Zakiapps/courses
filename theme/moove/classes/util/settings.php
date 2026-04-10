<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Theme helper to load a theme configuration.
 *
 * @package    theme_moove
 * @copyright  2022 Willian Mano - http://conecti.me
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace theme_moove\util;

use theme_config;

/**
 * Helper to load a theme configuration.
 *
 * @package    theme_moove
 * @copyright  2017 Willian Mano - http://conecti.me
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings {
    /**
     * @var \stdClass $theme The theme object.
     */
    protected $theme;
    /**
     * @var array $files Theme file settings.
     */
    protected $files = [
        'logo', 'logodark', 'loginbg',
        'sliderimage1', 'sliderimage2', 'sliderimage3', 'sliderimage4', 'sliderimage5', 'sliderimage6',
        'sliderimage7', 'sliderimage8', 'sliderimage9', 'sliderimage10', 'sliderimage11', 'sliderimage12',
        'marketing1icon', 'marketing2icon', 'marketing3icon', 'marketing4icon',
    ];

    /**
     * Class constructor
     */
    public function __construct() {
        $this->theme = theme_config::load('moove');
    }

    /**
     * Magic method to get theme settings
     *
     * @param string $name
     *
     * @return false|string|null
     */
    public function __get(string $name) {
        if (in_array($name, $this->files)) {
            return $this->theme->setting_file_url($name, $name);
        }

        if (empty($this->theme->settings->$name)) {
            return false;
        }

        return $this->theme->settings->$name;
    }

    /**
     * Get footer settings
     *
     * @return array
     */
    public function footer() {
        global $CFG;

        $templatecontext = [];

        $settings = [
            'facebook', 'twitter', 'linkedin', 'youtube', 'instagram', 'whatsapp', 'telegram', 'tiktok', 'pinterest',
            'website', 'mobile', 'mail',
        ];

        $templatecontext['hasfootercontact'] = false;
        $templatecontext['hasfootersocial'] = false;
        foreach ($settings as $setting) {
            $templatecontext[$setting] = $this->$setting;

            if (in_array($setting, ['website', 'mobile', 'mail']) && !empty($templatecontext[$setting])) {
                $templatecontext['hasfootercontact'] = true;
            }

            $socialsettings = [
                'facebook', 'twitter', 'linkedin', 'youtube', 'instagram', 'whatsapp', 'telegram', 'tiktok', 'pinterest',
            ];

            if (in_array($setting, $socialsettings) && !empty($templatecontext[$setting])) {
                $templatecontext['hasfootersocial'] = true;
            }
        }

        $templatecontext['enablemobilewebservice'] = $CFG->enablemobilewebservice;

        if ($CFG->enablemobilewebservice) {
            $iosappid = get_config('tool_mobile', 'iosappid');
            if (!empty($iosappid)) {
                $templatecontext['iosappid'] = $iosappid;
            }

            $androidappid = get_config('tool_mobile', 'androidappid');
            if (!empty($androidappid)) {
                $templatecontext['androidappid'] = $androidappid;
            }

            $setuplink = get_config('tool_mobile', 'setuplink');
            if (!empty($setuplink)) {
                $templatecontext['mobilesetuplink'] = $setuplink;
            }
        }

        $templatecontext['gt4t_footer'] = [
            'description' => 'A Europe-wide initiative empowering universities and innovators to lead the green and digital transition.',
            'disclaimer' => 'GT4T is supported by the EIT HEI Initiative, and guided and co-funded by EIT Climate-KIC.',
            'copyright' => "\u{00a9} Green Tech for Transformation " . date('Y'),
            'wwwroot' => $CFG->wwwroot,
        ];

        return $templatecontext;
    }

    /**
     * Get frontpage settings
     *
     * @return array
     */
    public function frontpage() {
        return array_merge(
            $this->frontpage_slideshow(),
            $this->frontpage_marketingboxes(),
            $this->frontpage_numbers(),
            $this->faq(),
            $this->gt4t_frontpage()
        );
    }

    /**
     * Get GT4T-specific frontpage section data
     *
     * @return array
     */
    public function gt4t_frontpage() {
        global $CFG;

        $templatecontext = [];
        $templatecontext['gt4t'] = true;
        $templatecontext['wwwroot'] = $CFG->wwwroot;

        $templatecontext['gt4t_hero'] = [
            'heading' => "Empowering Europe\u{2019}s Green and Digital Future",
            'subtitle' => 'GT4T unites universities, innovators, and entrepreneurs to turn research into real-world solutions driving sustainability, digital transformation, and deep-tech growth across Europe.',
            'cta_text' => 'Join the transformation',
            'cta_url' => $CFG->wwwroot . '/login/index.php',
        ];

        $templatecontext['gt4t_education'] = [
            'heading' => 'Transforming Higher Education into Innovation Powerhouses',
            'text1' => 'Higher education institutions are central to Europe\'s green and digital transition, but unlocking their full innovation potential requires new tools, skills, and collaboration models. GT4T supports HEIs in strengthening their role as engines of sustainable innovation and entrepreneurship.',
            'text2' => 'Through Venture Science Labs, challenge-based learning, and cross-border cooperation, GT4T enables researchers, students, and professionals to work on real-world challenges and develop solutions that move from classrooms and laboratories to startups, pilots, and market-ready innovations.',
        ];

        $templatecontext['gt4t_stats'] = [
            [
                'number' => '30',
                'label' => 'start-ups and 60 strategic partnerships created by 2027',
            ],
            [
                'number' => '1,000+',
                'label' => 'participants trained across Europe',
            ],
            [
                'number' => '3',
                'label' => 'deep-tech innovations prepared for real-world deployment',
            ],
        ];

        $templatecontext['gt4t_domains'] = [
            'heading' => 'Where Innovation Meets Sustainability',
            'subtitle' => 'GT4T focuses on key domains where education, technology, and entrepreneurship can deliver the greatest societal impact.',
            'items' => [
                [
                    'title' => 'Circular Economy',
                    'description' => 'Turning waste into value through smart design, sustainable materials, and resource-efficient systems that support long-term environmental and economic resilience.',
                    'color' => 'green',
                    'color_hex' => '#19B35C',
                ],
                [
                    'title' => 'Digital Transformation',
                    'description' => "Building intelligent, data-driven solutions \u{2014} from AI to smart systems \u{2014} that enable more connected, efficient, and inclusive industries and services.",
                    'color' => 'cyan',
                    'color_hex' => '#00D4D4',
                ],
                [
                    'title' => 'Clean Energy',
                    'description' => "Accelerating the transition toward renewable, low-emission energy solutions that support Europe\u{2019}s climate goals and energy independence.",
                    'color' => 'teal',
                    'color_hex' => '#006D77',
                ],
            ],
        ];

        $templatecontext['gt4t_innovators'] = [
            'heading' => "Powered by Europe\u{2019}s Leading Innovators",
            'text1' => "GT4T is built on collaboration. From Finland to T\u{00fc}rkiye, our network brings together universities, startups, industry partners, innovation hubs, and regional stakeholders to create a vibrant, cross-border ecosystem for sustainable innovation.",
            'text2' => "By combining local expertise with European cooperation, GT4T strengthens regional innovation capacity while contributing to Europe\u{2019}s shared green and digital ambitions.",
            'cta_text' => 'Meet our partners',
            'cta_url' => $CFG->wwwroot . '/course/index.php',
        ];

        $templatecontext['gt4t_change'] = [
            'heading' => 'Be Part of the Change',
            'text' => "GT4T is open to everyone shaping Europe\u{2019}s future; students, researchers, entrepreneurs, educators, and policymakers alike. Whether you want to learn new skills, collaborate across borders, or turn ideas into action, GT4T offers pathways to get involved. From training programmes and challenges to startup support and community events, GT4T creates opportunities to grow, connect, and lead meaningful change.",
            'cta_text' => 'Explore Opportunities',
            'cta_url' => $CFG->wwwroot . '/course/index.php',
        ];

        $templatecontext['gt4t_movement'] = [
            'heading' => 'Join the GreenTech4Transformation Movement',
            'subtitle' => "Together, we\u{2019}re building a smarter, greener Europe.",
            'cta_text' => 'Contact Us',
            'cta_url' => $CFG->wwwroot . '/login/index.php',
        ];

        return $templatecontext;
    }

    /**
     * Get config theme slideshow
     *
     * @return array
     */
    public function frontpage_slideshow() {
        $templatecontext['slidercount'] = $this->slidercount;

        $defaultimage = new \moodle_url('/theme/moove/pix/default_slide.jpg');
        for ($i = 1, $j = 0; $i <= $templatecontext['slidercount']; $i++, $j++) {
            $sliderimage = "sliderimage{$i}";
            $slidertitle = "slidertitle{$i}";
            $slidercap = "slidercap{$i}";
            $slidercapcontent = $this->$slidercap ?: null;

            $slidetitle = format_string($this->$slidertitle) ?: null;
            $slidecontent = format_text($slidercapcontent, FORMAT_MOODLE, ['noclean' => false]) ?: null;
            $image = $this->$sliderimage;

            $hascaption = isset($slidetitle) || isset($slidecontent);

            $templatecontext['slides'][$j]['key'] = $j;
            $templatecontext['slides'][$j]['active'] = $i === 1;
            $templatecontext['slides'][$j]['image'] = $image ?: $defaultimage->out();
            $templatecontext['slides'][$j]['title'] = $slidetitle;
            $templatecontext['slides'][$j]['caption'] = $slidecontent;
            $templatecontext['slides'][$j]['hascaption'] = $hascaption;
        }

        $templatecontext['slidersingleslide'] = $this->slidercount == 1;

        return $templatecontext;
    }

    /**
     * Get config theme slideshow
     *
     * @return array
     */
    public function frontpage_marketingboxes() {
        if ($templatecontext['displaymarketingbox'] = $this->displaymarketingbox) {
            $templatecontext['marketingheading'] = format_text($this->marketingheading, FORMAT_HTML);
            $templatecontext['marketingcontent'] = format_text($this->marketingcontent, FORMAT_HTML);

            $defaultimage = new \moodle_url('/theme/moove/pix/default_markegingicon.svg');

            for ($i = 1, $j = 0; $i < 5; $i++, $j++) {
                $marketingicon = 'marketing' . $i . 'icon';
                $marketingheading = 'marketing' . $i . 'heading';
                $marketingcontent = 'marketing' . $i . 'content';

                $templatecontext['marketingboxes'][$j]['icon'] = $this->$marketingicon ?: $defaultimage->out();
                $templatecontext['marketingboxes'][$j]['heading'] = $this->$marketingheading ?
                    format_text($this->$marketingheading, FORMAT_HTML) : 'Lorem';
                $templatecontext['marketingboxes'][$j]['content'] = $this->$marketingcontent ?
                    format_text($this->$marketingcontent, FORMAT_HTML) :
                    'Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod.';
            }
        }

        return $templatecontext;
    }

    /**
     * Get config theme slideshow
     *
     * @return array
     */
    public function frontpage_numbers() {
        global $DB;

        if ($templatecontext['numbersfrontpage'] = $this->numbersfrontpage) {
            $templatecontext['numberscontent'] = $this->numbersfrontpagecontent ? format_text($this->numbersfrontpagecontent) : '';
            $templatecontext['numbersusers'] = $DB->count_records('user', ['deleted' => 0, 'suspended' => 0]) - 1;
            $templatecontext['numberscourses'] = $DB->count_records('course', ['visible' => 1]) - 1;
        }

        return $templatecontext;
    }

    /**
     * Get config theme slideshow
     *
     * @return array
     */
    public function faq() {
        $templatecontext['faqenabled'] = false;

        if ($this->faqcount) {
            for ($i = 1; $i <= $this->faqcount; $i++) {
                $faqquestion = 'faqquestion' . $i;
                $faqanswer = 'faqanswer' . $i;

                if (!$this->$faqquestion || !$this->$faqanswer) {
                    continue;
                }

                $templatecontext['faq'][] = [
                    'id' => $i,
                    'question' => format_text($this->$faqquestion),
                    'answer' => format_text($this->$faqanswer),
                    'active' => $i === 1,
                ];
            }

            if (!empty($templatecontext['faq'])) {
                $templatecontext['faqenabled'] = true;
            }
        }

        return $templatecontext;
    }
}
