import gulp from 'gulp';
import plugins from 'gulp-load-plugins';
import runSequence from 'run-sequence';
import events from 'events';
import taskSettings from './resources/gulpTasks/settings';
import taskCss from './resources/gulpTasks/css';
import taskImage from './resources/gulpTasks/image';
import taskWebpack from './resources/gulpTasks/webpack';

events.EventEmitter.defaultMaxListeners = Infinity;

const $ = plugins();

gulp.task('default', (callback) => {
  runSequence(
    'jscs',
    'clean:assets',
    'copy:assets',
    'assets:tree',
    'assets:draft',
    'assets:sass',
    'assets:image',
    'webpack',
    'assets:chunk',
    callback);
});

// s: settings
gulp.task('jscs', taskSettings['jscs']);
gulp.task('clean:assets', taskSettings['clean:assets']);
gulp.task('copy:assets', taskSettings['copy:assets']);

gulp.task('assets:chunk', taskSettings['assets:chunk']);
gulp.task('assets:tree', taskSettings['assets:tree']);
gulp.task('assets:draft', taskSettings['assets:draft']);

gulp.task('jsdoc', taskSettings['jsdoc']);
// e: settings

// s: css
gulp.task('assets:sass', taskCss['assets:sass']);
// e: css

// s: image
gulp.task('assets:image', taskImage['assets:image']);
// e: image

gulp.task('webpack', taskWebpack['webpack']);

gulp.task('sass:watch', () => {
  return gulp.watch([
   './resources/assets/**/*.scss',
  ], ['assets:sass']);
});


