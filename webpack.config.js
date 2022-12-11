const env = process.env.NODE_ENV
const path = require('path');

module.exports = {
  mode: env || 'development',
  entry: './public/js/index.js',
  output: {
    filename: 'bundle.js',
    path: path.resolve(__dirname, 'public', 'dist')
  },
  module: {
    rules: [
      {
        test: /\.m?js$/,
        exclude: /node_modules/,
        use: {
          loader: 'babel-loader',
          options: {
            presets: [
              ['@babel/preset-env', {
                targets: [
                  'ie >= 6',
                  'firefox >= 12'
                ],
                debug: ['development', 'none'].includes(env) ? true : false,
                useBuiltIns: 'usage',
                corejs: '3.26'
              }]
            ]
          }
        }
      }
    ]
  },
  target: ['web', 'es5'],
  devServer: {
    static:  {
      directory: path.join(__dirname, 'public')
    },
    compress: true,
    port: 9000
  }
}
