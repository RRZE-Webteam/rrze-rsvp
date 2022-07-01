const autoprefixer = require("autoprefixer");
const MiniCSSExtractPlugin = require("mini-css-extract-plugin");
const CSSMinimizerPlugin = require("css-minimizer-webpack-plugin");
const TerserPlugin = require("terser-webpack-plugin");

const path = require("path");
const admin = path.join(__dirname, "src", "admin");
const menu = path.join(__dirname, "src", "menu");
const shortcode = path.join(__dirname, "src", "shortcode");
const seat = path.join(__dirname, "src", "seat");
const occupancy = path.join(__dirname, "src", "occupancy");
const reload = path.join(__dirname, "src", "reload");

module.exports = (env, argv) => {
    function isDevelopment() {
        return argv.mode === "development";
    }
    var config = {
        entry: {
            admin,
            menu,
            shortcode,
            seat,
            occupancy,
            reload,
        },
        output: {
            path: path.resolve(__dirname, "build"),
            filename: "[name].js",
            clean: true,
        },
        optimization: {
            minimizer: [
                new CSSMinimizerPlugin(),
                new TerserPlugin({ terserOptions: { sourceMap: true } }),
            ],
        },
        plugins: [
            new MiniCSSExtractPlugin({
                chunkFilename: "[id].css",
                filename: (chunkData) => {
                    return "[name].css";
                },
            }),
        ],
        devtool: isDevelopment() ? "cheap-module-source-map" : "source-map",
        module: {
            rules: [
                {
                    test: /\.js$/,
                    exclude: /node_modules/,
                    use: [
                        {
                            loader: "babel-loader",
                            options: {
                                presets: ["@babel/preset-env"],
                            },
                        },
                    ],
                },
                {
                    test: /\.(sa|sc|c)ss$/,
                    use: [
                        MiniCSSExtractPlugin.loader,
                        "css-loader",
                        {
                            loader: "postcss-loader",
                            options: {
                                postcssOptions: {
                                    plugins: [autoprefixer()],
                                },
                            },
                        },
                        "sass-loader",
                    ],
                },
            ],
        },
    };
    return config;
};
