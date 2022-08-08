let gulp = require("gulp");
let bump = require("gulp-bump");
let semver = require("semver");
let touch = require("gulp-touch-cmd");
let info = require("./package.json");
let zip = require("gulp-zip");

// Major: 1.0.0
gulp.task("major", async () => {
    let v = semver.inc(info.version, "major");
    gulp.src(["./" + info.main, "./package.json", "./composer.json"])
        .pipe(bump({ version: v }))
        .pipe(gulp.dest("./"))
        .pipe(touch());
});

// Minor: 0.1.0
gulp.task("minor", async () => {
    let v = semver.inc(info.version, "minor");
    gulp.src(["./" + info.main, "./package.json", "./composer.json"])
        .pipe(bump({ version: v }))
        .pipe(gulp.dest("./"))
        .pipe(touch());
});

// Patch: 0.0.2
gulp.task("patch", async () => {
    let v = semver.inc(info.version, "patch");
    gulp.src(["./" + info.main, "./package.json", "./composer.json"])
        .pipe(bump({ version: v }))
        .pipe(gulp.dest("./"))
        .pipe(touch());
});

// Prerelease: 0.0.1-2
gulp.task("prerelease", async () => {
    let v = semver.inc(info.version, "prerelease");
    gulp.src(["./" + info.main, "./package.json", "./composer.json"])
        .pipe(bump({ version: v }))
        .pipe(gulp.dest("./"))
        .pipe(touch());
});

// Create a bundle.
gulp.task("bundle", async () => {
    gulp.src([
        "**/*",
        "!node_modules/**",
        "!src/**",
        "!bundle/**",
        "!gulpfile.js",
        "!package.json",
        "!package-lock.json",
        "!webpack.config.js",
        "!composer.json",
        "!composer.lock",
        "!.gitignore",
    ])
        .pipe(zip(info.name + ".zip"))
        .pipe(gulp.dest("bundle"))
        .pipe(touch());
});
